<?php

namespace App\Domain\Cajas\V1;

use App\Models\CollectionMovement;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DirectCustody
{
    public function __construct(private IntegrationAccess $access, private PilotSignature $signature, private CutPayouts $payouts) {}

    public function payment(string $id): array
    {
        $payment = CollectionMovement::with('operator')->where('public_id', $id)
            ->where('payment_method', 'cash')->where('confirmation_status', 'applied')
            ->where('idempotency_key', 'not like', 'cajas-cut-item-%')->first();
        if (! $payment || ! $payment->operator || $payment->totalCents() <= 0) {
            $this->access->fail(404, 'PAYMENT_NOT_FOUND', 'El pago nativo en efectivo aplicado no está disponible.');
        }
        $this->access->authorizeOperator($payment->operator->public_id);

        return [
            'payment_id' => $payment->public_id, 'payment_folio' => $payment->folio, 'operator' => $payment->operator->public_id,
            'currency' => 'MXN', 'amount_cents' => $payment->totalCents(), 'paid_on' => $payment->operated_on->toDateString(),
            'status' => 'applied',
        ];
    }

    public function replay(string $movementId, string $account, string $hash): ?array
    {
        $stored = DB::table('cajas_v1_custody_confirmations')->where('cash_movement_id', $movementId)->first();
        if (! $stored) {
            return null;
        }
        $this->access->authorizeCustodyAccount($stored->cash_account_id);
        if ($stored->cash_account_id !== $account || ! hash_equals($stored->body_hash, $hash)) {
            $this->access->fail(409, 'IDEMPOTENCY_KEY_REUSED', 'El movimiento de custodia ya tiene otro contenido.');
        }

        $body = json_decode($stored->body, true, flags: JSON_THROW_ON_ERROR);
        if (($body['kind'] ?? null) === 'payout_return_transfer') {
            $this->access->authorizeCustodyAccount($body['source_cash_account_id']);
        }

        return json_decode($stored->result, true, flags: JSON_THROW_ON_ERROR);
    }

    public function confirm(array $data, string $body, string $hash, string $proof): array
    {
        $account = $data['cash_account_id'];
        $movementId = $data['cash_movement_id'];
        $accounts = [$account];
        if ($data['kind'] === 'payout_return_transfer') {
            $this->access->authorizeCustodyAccount($data['source_cash_account_id']);
            $accounts[] = $data['source_cash_account_id'];
            sort($accounts, SORT_STRING);
        }
        $this->verifyProof($data, $hash, $proof);

        return DB::transaction(function () use ($data, $body, $hash, $account, $movementId, $accounts): array {
            // Lock in a stable order to serialize both legs with other transfers and custody closes.
            foreach ($accounts as $box) {
                DB::table('cajas_v1_custody_openings')->where('cash_account_id', $box)->update(['id' => DB::raw('id')]);
            }
            if (($previous = $this->replay($movementId, $account, $hash)) !== null) {
                return $previous;
            }
            $instant = CarbonImmutable::parse($data['confirmed_at']);
            if ($instant->timestamp > now()->timestamp + 30
                || $data['effective_on'] > $instant->setTimezone('America/Merida')->toDateString()) {
                $this->conflict('La fecha de la entrega efectiva no corresponde a la confirmación.');
            }
            $effectiveAt = $instant->utc()->toISOString();
            $effects = match ($data['kind']) {
                'direct_payment_receipt' => [[$account, ...$this->receipt($data)]],
                'correction_refund' => [[$account, ...$this->refund($data)]],
                'payout_return_transfer' => $this->transfer($data, $effectiveAt),
            };
            $entries = DB::table('cajas_v1_custody_movements');
            $actor = User::where('email', $this->access->setting('audit_user_email'))
                ->where('status', 'active')->whereNull('locked_at')->first()
                ?? throw new RuntimeException('Falta configurar el usuario de auditoría del piloto.');
            $recordedAt = now()->utc()->toISOString();
            $destinationId = null;
            foreach ($effects as [$box, $origin, $amount]) {
                if ((clone $entries)->where('financial_origin', $origin)->exists()
                    || (clone $entries)->where('cash_account_id', $box)->where('reference', $movementId)->where('source', 'prestamos')->exists()) {
                    $this->conflict('El hecho financiero ya tiene un efecto reconocido en custodia.');
                }
                $id = $entries->insertGetId([
                    'cash_account_id' => $box, 'reference' => $movementId, 'financial_origin' => $origin, 'source' => 'prestamos',
                    'amount_cents' => $amount, 'effective_at' => $effectiveAt, 'recorded_at' => $recordedAt,
                    'evidence_ref' => $data['evidence_ref'], 'status' => 'confirmed', 'authorized_by' => $actor->id,
                ]);
                if ($box === $account) {
                    $destinationId = $id;
                }
            }
            $result = [
                'cash_movement_id' => $movementId, 'cash_account_id' => $account, 'currency' => 'MXN',
                'kind' => $data['kind'], 'status' => 'recognized', 'amount_cents' => $data['amount_cents'],
            ];
            DB::table('cajas_v1_custody_confirmations')->insert([
                'cash_movement_id' => $movementId, 'cash_account_id' => $account, 'custody_movement_id' => $destinationId,
                'body_hash' => $hash, 'body' => $body, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $result;
        }, 5);
    }

    private function verifyProof(array $data, string $hash, string $proof): void
    {
        $claims = $this->signature->claims($proof);
        $expected = ['iss' => 'cajas', 'aud' => 'prestamos', 'environment' => $this->access->proofEnvironment(),
            'operation' => 'confirm_custody', 'body_sha256' => $hash,
            'cash_movement_id' => $data['cash_movement_id'], 'cash_account_id' => $data['cash_account_id'],
            'confirmed_by' => $data['confirmed_by']];
        foreach ($expected as $field => $value) {
            if (($claims[$field] ?? null) !== $value) {
                $this->access->fail(403, 'CUSTODY_PROOF_INVALID', 'La entrega confirmada requiere evidencia firmada por Cajas.');
            }
        }
    }

    private function receipt(array $data): array
    {
        $payment = $this->payment($data['payment_id']);
        if ($payment['payment_folio'] !== $data['payment_folio'] || $payment['amount_cents'] !== $data['amount_cents']
            || $data['effective_on'] < $payment['paid_on']) {
            $this->conflict('La custodia no coincide con el folio, importe o fecha del pago.');
        }

        return ['direct_payment_receipt:'.$data['payment_id'], $payment['amount_cents']];
    }

    private function refund(array $data): array
    {
        $record = DB::table('cajas_v1_payment_corrections as correction')
            ->join('cajas_v1_cut_executions as cut', 'cut.id', '=', 'correction.cut_execution_id')
            ->join('collection_movements as original', 'original.id', '=', 'correction.original_movement_id')
            ->join('collection_movements as replacement', 'replacement.id', '=', 'correction.replacement_movement_id')
            ->where('correction.correction_id', $data['correction_id'])->first([
                'cut.body', 'correction.operator', 'original.contract_amount as original_amount',
                'replacement.contract_amount as replacement_amount',
            ]);
        if (! $record) {
            $this->conflict('La devolución requiere una corrección financiera aplicada.');
        }
        $this->access->authorizeOperator($record->operator);
        $receipts = json_decode($record->body, true, flags: JSON_THROW_ON_ERROR)['receipts'];
        $receipt = count($receipts) === 1 ? $receipts[0] : [];
        $excess = Money::cents($record->original_amount) - Money::cents($record->replacement_amount);
        if (($receipt['receipt_id'] ?? null) !== $data['receipt_id'] || $receipt['cash_account_id'] !== $data['cash_account_id']
            || $excess <= 0 || $excess > $receipt['amount_cents'] || $excess !== $data['amount_cents']
            || $data['effective_on'] < $receipt['received_on']) {
            $this->conflict('La devolución no coincide con el recibo y excedente de la corrección aplicada.');
        }

        return ['correction_refund:'.$data['correction_id'], -$excess];
    }

    private function transfer(array $data, string $effectiveAt): array
    {
        $returnId = $data['payout_return_id'];
        $source = $data['source_cash_account_id'];
        $destination = $data['cash_account_id'];
        $reference = $this->payouts->appliedCustodyEntry($returnId, 'return');
        if (! $reference) {
            $this->conflict('El traspaso requiere una devolución incluida en un corte aplicado.');
        }
        $this->access->authorizeOperator($reference['operator']);

        $return = $reference['entry'];
        $payout = $this->payouts->appliedCustodyEntry($return['payout_id'], 'payout');
        if (! $payout || $payout['cut_id'] !== $reference['cut_id'] || $payout['entry']['cash_account_id'] !== $destination) {
            $this->conflict('La caja de destino debe corresponder a la salida del egreso original.');
        }
        $recognized = DB::table('cajas_v1_custody_movements')->where('financial_origin', 'payout_return:'.$returnId)
            ->where('source', 'prestamos')->where('status', 'confirmed')->first();
        $amount = $data['amount_cents'];
        if ($return['cash_account_id'] !== $source || $return['amount_cents'] !== $amount
            || ! $recognized || $recognized->cash_account_id !== $source || $recognized->reference !== $returnId
            || (int) $recognized->amount_cents !== $amount || $recognized->effective_at > $effectiveAt
            || $return['received_on'] > $data['effective_on']) {
            $this->conflict('El traspaso debe corresponder a la devolución íntegra reconocida en la caja de origen.');
        }

        foreach ([$source, $destination] as $box) {
            $opening = DB::table('cajas_v1_custody_openings')->where('cash_account_id', $box)->first();
            if (! $opening || $opening->cash_account_id !== $box || $opening->cutoff_at > $effectiveAt) {
                $this->conflict('Ambas cajas requieren una apertura reconocida anterior al traspaso.');
            }
        }

        return [
            [$source, 'payout_return_transfer_out:'.$returnId, -$amount],
            [$destination, 'payout_return_transfer_in:'.$returnId, $amount],
        ];
    }

    private function conflict(string $message): never
    {
        $this->access->fail(409, 'CUSTODY_CONFLICT', $message);
    }
}
