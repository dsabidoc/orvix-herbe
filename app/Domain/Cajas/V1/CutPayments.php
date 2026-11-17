<?php

namespace App\Domain\Cajas\V1;

use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CutPayments
{
    public function __construct(
        private IntegrationAccess $access,
        private OrdinaryPayment $ordinary,
        private PendingCredits $credits,
        private OperatorCutLedger $ledger,
        private DirectFunding $funding,
        private CutPayouts $payouts,
    ) {}

    public function validateItemChannel(array $item): void
    {
        if (collect($item['extras'])->contains(fn ($extra) => is_array($extra) && ($extra['type'] ?? null) === 'collection_fee')) {
            $detail = ['item_id' => $item['item_id'], ...array_intersect_key($item, ['installment_id' => true]), 'channel' => 'prestamos'];
            $this->access->fail(422, 'CAPTURE_CHANNEL_MISMATCH', 'El cobro incluye cuota de cobranza y corresponde a Préstamos.', [$detail]);
        }
        if ($item['extras'] !== [] || (int) $item['contract_amount_cents'] !== (int) $item['reported_amount_cents']) {
            $this->access->fail(422, 'CAPTURE_CHANNEL_MISMATCH', 'Este piloto aplica solamente capital e interés, sin cargos adicionales.');
        }
    }

    public function reconcile(array $cut, int $status): array
    {
        $reported = array_sum(array_column($cut['items'], 'reported_amount_cents'));
        $received = array_sum(array_column($cut['receipts'], 'amount_cents'));
        $offset = array_sum(array_column($cut['offsets'], 'amount_cents'));
        $paid = array_sum($this->payouts->netAmounts($cut, $status));
        $difference = $reported - $received - $offset + $paid;
        if ($difference !== 0 || $reported !== $cut['totals']['reported_cents']
            || $received !== $cut['totals']['received_cents'] || $offset !== $cut['totals']['offset_cents']
            || $paid !== $cut['totals']['paid_cents'] || $difference !== $cut['totals']['remaining_cents']) {
            $this->access->fail($status, 'CUT_TOTALS_MISMATCH', 'Los movimientos no coinciden con los totales conciliados del corte.');
        }

        return [
            'reported_cents' => $reported, 'received_effect_cents' => -$received,
            'offset_effect_cents' => -$offset, 'payout_effect_cents' => $paid, 'difference_cents' => $difference,
        ];
    }

    public function preview(array $cut, bool $lock = false): array
    {
        if (! $this->access->usesCajas($cut['operator'], $cut['cut_on'])) {
            $this->access->fail(422, 'CAPTURE_CHANNEL_MISMATCH', 'La fecha y el operador no están asignados a Cajas.');
        }

        $planned = [];
        $seen = [];
        foreach ($cut['items'] as $item) {
            $this->validateItemChannel($item);
            $type = $item['payment_type'];
            $grouped = $type === 'general_capital';
            $targets = $grouped ? $item['installments'] : [$item];
            $letters = [];
            $allocations = [];

            foreach ($targets as $target) {
                $id = $target['installment_id'];
                $detail = ['item_id' => $item['item_id'], 'installment_id' => $id];
                if (isset($seen[$id])) {
                    $this->access->fail(422, 'INVALID_REQUEST', 'Una letra solo puede aparecer una vez en el corte.', [$detail]);
                }
                $seen[$id] = true;
                $letter = $this->findLetter($cut['operator'], $target['installment_version'], $detail, $lock);
                if (! in_array($grouped ? 'capital' : $type, $this->ordinary->allowedTypes($letter, $cut['cut_on']), true)
                    || ($grouped && $letter->loan->public_id !== $item['loan_id'])) {
                    $this->access->fail(422, 'PAYMENT_NOT_SUPPORTED', 'La letra no admite este tipo de pago en el corte.', [$detail]);
                }

                $amount = $grouped ? $target['principal_cents'] : $item['contract_amount_cents'];
                $expected = in_array($type, ['capital', 'general_capital'], true)
                    ? $this->ordinary->principalCents($letter) : Money::cents($letter->remaining_amount);
                if ($type === 'ordinary' ? $amount > $expected : $amount !== $expected) {
                    $this->access->fail(422, 'PAYMENT_AMOUNT_INVALID', 'El importe no coincide con el saldo exigido para este pago.', [$detail]);
                }

                $letters[] = $letter;
                $allocations[] = $this->ordinary->allocation($letter, $amount, $type);
            }

            if ($grouped) {
                $detail = [['item_id' => $item['item_id'], 'loan_id' => $item['loan_id']]];
                $tail = $this->ordinary->pendingTail($letters[0]->loan)->limit(count($letters));
                $expectedIds = ($lock ? $tail->lockForUpdate() : $tail)->pluck('id')->all();
                if ($expectedIds !== array_map(fn (Installment $letter) => $letter->id, $letters)) {
                    $this->access->fail(422, 'PAYMENT_NOT_SUPPORTED', 'El adelanto debe comenzar en la última letra pendiente y avanzar sin saltos.', $detail);
                }
                if ($item['contract_amount_cents'] !== array_sum(array_column($allocations, 'principal_cents'))) {
                    $this->access->fail(422, 'PAYMENT_AMOUNT_INVALID', 'El importe no coincide con la suma de capital del grupo.', $detail);
                }
            }

            $planned[] = [
                'letters' => $letters,
                'type' => $type,
                'result' => [
                    'item_id' => $item['item_id'],
                    'contract_amount_cents' => $item['contract_amount_cents'],
                    'reported_amount_cents' => $item['reported_amount_cents'],
                    'extras' => [],
                    'allocations' => $allocations,
                ],
            ];
        }

        return $planned;
    }

    private function findLetter(string $operator, string $version, array $detail, bool $lock): Installment
    {
        $id = $detail['installment_id'];
        $query = Installment::with(['loan.operator', 'allocations.movement'])->where('public_id', $id);
        $letter = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $letter || $letter->loan->operator?->public_id !== $operator) {
            // Revalidation uses the current rows for both the letter and its replacement history.
            $replacement = DB::table('cajas_v1_installment_replacements')->where('public_id', $id)
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($replacement && $letter === null && Loan::where('public_id', $replacement->loan_public_id)
                ->whereHas('operator', fn ($query) => $query->where('public_id', $operator))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first(['id'])) {
                $this->access->fail(409, 'INSTALLMENT_REPLACED', 'La letra fue sustituida.', [[
                    ...$detail, 'replacement_installment_ids' => $this->replacementIds($replacement->replacement_public_id, $replacement->loan_public_id, $lock),
                ]]);
            }
            $this->access->fail(409, 'INSTALLMENT_CHANGED', 'La letra ya no está disponible para este operador.', [$detail]);
        }

        $pending = $this->ordinary->hasPendingPayment($letter);
        if ($pending) {
            $this->access->fail(409, 'PAYMENT_PENDING_CONFIRMATION', 'El crédito tiene un cobro por confirmar.', [$detail]);
        }
        $currentVersion = $this->ordinary->version($letter, $pending);
        if (! hash_equals($currentVersion, $version)) {
            $this->access->fail(409, 'INSTALLMENT_CHANGED', 'Cambió la letra en Préstamos.', [[
                ...$detail, 'sent_version' => $version, 'current_version' => $currentVersion,
                'current_remaining_cents' => Money::cents($letter->remaining_amount),
            ]]);
        }

        return $letter;
    }

    public function replacementIds(?string $id, string $loanId, bool $lock = false): array
    {
        while ($id !== null) {
            if (Installment::where('public_id', $id)->whereHas('loan', fn ($loan) => $loan->where('public_id', $loanId))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first(['id'])) {
                return [$id];
            }
            $id = DB::table('cajas_v1_installment_replacements')->where('loan_public_id', $loanId)
                ->where('public_id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->value('replacement_public_id');
        }

        return [];
    }

    public function processNext(): bool
    {
        if (! $this->access->enabled()) {
            return false;
        }

        $execution = DB::table('cajas_v1_cut_executions')->whereIn('status', ['received', 'processing'])
            ->oldest('updated_at')->oldest('id')->first();
        if (! $execution) {
            return false;
        }

        DB::table('cajas_v1_cut_executions')->where('id', $execution->id)->whereIn('status', ['received', 'processing'])
            ->update(['status' => 'processing', 'updated_at' => now()]);

        DB::transaction(function () use ($execution): void {
            // SQLite takes its write lock before checking the execution and its balances.
            DB::table('cajas_v1_cut_executions')->where('id', $execution->id)->update(['id' => DB::raw('id')]);
            $execution = DB::table('cajas_v1_cut_executions')->where('id', $execution->id)->first();
            if ($execution->status !== 'processing') {
                return;
            }
            if (! hash_equals($execution->body_hash, hash('sha256', $execution->body))) {
                throw new RuntimeException('El cuerpo persistido no coincide con el corte autorizado.');
            }
            $cut = json_decode($execution->body, true, flags: JSON_THROW_ON_ERROR);
            try {
                $result = DB::transaction(fn () => $this->apply($cut));
            } catch (HttpResponseException $conflict) {
                $result = [
                    'status' => 'requires_review', 'payments' => [], 'pending_credits' => [],
                    'error' => $conflict->getResponse()->getData(true)['error'],
                ];
            }
            DB::table('cajas_v1_cut_executions')->where('id', $execution->id)->update([
                'status' => $result['status'], 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        }, 5);

        return true;
    }

    private function apply(array $cut): array
    {
        $operator = Operator::where('public_id', $cut['operator'])->where('status', 'active')->lockForUpdate()->first();
        if (! $operator) {
            $this->access->fail(409, 'OPERATOR_UNAVAILABLE', 'El operador dejó de estar activo.');
        }
        $actor = User::where('email', $this->access->setting('audit_user_email'))
            ->where('status', 'active')->whereNull('locked_at')->first();
        if (! $actor) {
            throw new RuntimeException('Falta configurar el usuario de auditoría del piloto.');
        }

        $operatorAccount = $this->reconcile($cut, 409);
        $planned = $this->preview($cut, lock: true);
        $pendingCredits = $this->credits->register($cut, $operator);
        $this->funding->registerCutDisbursements($cut, $operator, $actor);
        $payments = [];
        foreach ($planned as ['letters' => $letters, 'type' => $type, 'result' => $result]) {
            $publicId = (string) Str::ulid();
            $movement = CollectionMovement::create([
                'public_id' => $publicId, 'folio' => 'CAJAS-'.$publicId,
                'idempotency_key' => 'cajas-cut-item-'.hash('sha256', $cut['cut_id'].':'.$result['item_id']),
                'loan_id' => $letters[0]->loan_id,
                'target_installment_id' => $type === 'general_capital' ? null : $letters[0]->id,
                'operator_id' => $operator->id, 'registered_by' => $actor->id,
                'operated_on' => $cut['cut_on'], 'registered_at' => now('America/Merida'),
                'contract_amount' => Money::decimal($result['contract_amount_cents']),
                'type' => match ($type) {
                    'capital' => 'capital_advance',
                    'general_capital' => 'advance',
                    default => 'ordinary',
                },
                'payment_method' => 'cash', 'affects_investors' => true,
                'reference' => $type === 'capital_interest' ? PaymentApplicationService::FUTURE_MONTH_INTEREST_REFERENCE : $cut['approval']['id'],
                'confirmation_status' => 'reported',
            ]);
            $applied = app(PaymentApplicationService::class)->confirm($movement, $actor->id);
            $actual = $applied->allocations->keyBy('installment_id');
            if ($actual->count() !== count($letters)) {
                $this->access->fail(409, 'PAYMENT_APPLICATION_CHANGED', 'La aplicación difiere del pago validado.');
            }
            foreach ($letters as $index => $letter) {
                $expected = $result['allocations'][$index];
                $allocation = $actual->get($letter->id);
                $expectedStatus = match (true) {
                    $expected['remaining_after_cents'] > 0 => 'partial',
                    in_array($type, ['capital', 'general_capital'], true) => 'advanced',
                    default => 'confirmed',
                };
                if (! $allocation || $allocation->installment->status !== $expectedStatus
                    || $allocation->principal_cents !== $expected['principal_cents']
                    || $allocation->interest_waived_cents !== $expected['interest_waived_cents']
                    || Money::cents($allocation->amount) !== $expected['principal_cents'] + $expected['interest_cents']
                    || Money::cents($allocation->installment->remaining_amount) !== $expected['remaining_after_cents']) {
                    $this->access->fail(409, 'PAYMENT_APPLICATION_CHANGED', 'La aplicación difiere del pago validado.');
                }
            }
            $this->ledger->record($operator, $actor, $cut, 'cajas_v1_payment', $result['item_id'], $result['reported_amount_cents'], $publicId);
            $payments[] = [...$result, 'payment_id' => $publicId, 'payment_folio' => $applied->folio];
        }

        foreach ($cut['receipts'] as $receipt) {
            $this->ledger->record($operator, $actor, $cut, 'cajas_v1_receipt', $receipt['receipt_id'], -(int) $receipt['amount_cents']);
        }
        foreach ($cut['offsets'] as $offset) {
            $this->ledger->record($operator, $actor, $cut, 'cajas_v1_offset', $offset['offset_id'], -$offset['amount_cents'], $offset['pending_credit_id']);
        }
        foreach ($this->payouts->netAmounts($cut, 409) as $payoutId => $amount) {
            if ($amount !== 0) {
                $this->ledger->record($operator, $actor, $cut, 'cajas_v1_payout', (string) $payoutId, $amount);
            }
        }

        return [
            'status' => 'applied',
            'applied_at' => now()->utc()->toISOString(),
            'payments' => $payments,
            'operator_account' => $operatorAccount,
            'pending_credits' => $pendingCredits,
        ];
    }
}
