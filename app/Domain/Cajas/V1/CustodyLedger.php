<?php

namespace App\Domain\Cajas\V1;

use App\Models\CollectionMovement;
use App\Models\FundDisbursement;
use App\Models\User;
use App\Support\JsonData;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CustodyLedger
{
    public const SCOPE = 'prestamos_custody';

    public const ID = 'regex:/\A[A-Za-z0-9_-]{1,80}\z/';

    private const INSTANT = ['required', 'date', 'regex:/T.+Z\z/'];

    public function __construct(private IntegrationAccess $access, private CutPayouts $payouts) {}

    public function record(string $action, array $input, User $actor): array
    {
        if (! $this->access->enabled() || $actor->status !== 'active' || $actor->locked_at || ! $actor->can('investors.manage')) {
            throw new RuntimeException('El registro de custodia requiere acceso financiero autorizado.');
        }

        $id = ['required', self::ID];
        $amount = ['required', 'integer', 'between:-999999999999999,999999999999999'];
        $evidence = 'required|string|max:255';
        $rules = match ($action) {
            'opening' => [
                'opening_close_id' => $id, 'cutoff_at' => self::INSTANT,
                'amount_cents' => [...$amount, 'min:0'], 'evidence_ref' => $evidence,
            ],
            'recognize' => [
                'reference' => $id, 'financial_id' => $id, 'effective_at' => self::INSTANT,
                'financial_type' => 'required|in:payment,disbursement,payout,payout_return', 'evidence_ref' => $evidence,
            ],
            'observe' => [
                'reference' => $id, 'amount_cents' => [...$amount, 'not_in:0'], 'effective_at' => self::INSTANT,
                'status' => 'required|in:unmatched,pending', 'evidence_ref' => $evidence,
            ],
            'close' => [
                'close_id' => $id, 'opening_close_id' => $id, 'cutoff_at' => self::INSTANT,
                'coverage_confirmed' => 'required|boolean', 'pending_reasons' => 'sometimes|array|list|max:30',
                'pending_reasons.*' => ['required', self::ID, 'distinct:strict'], 'evidence_ref' => $evidence,
            ],
            default => throw new RuntimeException('Operación auxiliar desconocida.'),
        };
        $data = Validator::make($input, ['cash_account_id' => $id, ...$rules])->validate();
        $account = $data['cash_account_id'];
        $this->access->authorizeCustodyAccount($account);

        foreach (['cutoff_at', 'effective_at'] as $field) {
            if (isset($data[$field])) {
                $instant = CarbonImmutable::parse($data[$field]);
                if ($instant->isFuture()) {
                    throw new RuntimeException('El registro de custodia no puede tener fecha futura.');
                }
                $data[$field] = $instant->utc()->toISOString();
            }
        }

        return DB::transaction(function () use ($action, $data, $account, $actor): array {
            DB::table('cajas_v1_custody_openings')->where('cash_account_id', $account)->update(['id' => DB::raw('id')]);
            $opening = DB::table('cajas_v1_custody_openings')->where('cash_account_id', $account)->first();
            $audit = ['authorized_by' => $actor->id, 'recorded_at' => now()->utc()->toISOString()];

            return match ($action) {
                'opening' => $this->recordOpening($opening, $data, $audit),
                'close' => $this->recordClose($opening, $data, $audit),
                default => $this->recordMovement($opening, $action, $data, $audit),
            };
        }, 5);
    }

    public function result(string $account, string $close): ?array
    {
        $result = DB::table('cajas_v1_custody_closes')->where('cash_account_id', $account)->where('close_id', $close)->value('result');

        return $result === null ? null : json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }

    public function recognizeOpening(array $input, int $countedCents, User $actor): array
    {
        $pendingCents = $countedCents - $input['amount_cents'];
        if ($pendingCents < 0) {
            throw new RuntimeException('El importe respaldado supera el efectivo confirmado por Cajas.');
        }

        return DB::transaction(function () use ($input, $countedCents, $pendingCents, $actor): array {
            $opening = $this->record('opening', $input, $actor);
            if ($pendingCents > 0) {
                $this->record('observe', [
                    'cash_account_id' => $opening['cash_account_id'],
                    'reference' => 'opening-'.$opening['opening_close_id'],
                    'amount_cents' => $pendingCents,
                    'effective_at' => $opening['cutoff_at'],
                    'evidence_ref' => $opening['evidence_ref'],
                    'status' => 'pending',
                ], $actor);
            }

            return [...$opening, 'currency' => 'MXN', 'counted_cents' => $countedCents, 'pending_cents' => $pendingCents];
        }, 5);
    }

    private function recordOpening(?object $opening, array $data, array $audit): array
    {
        $data['amount_cents'] = (int) $data['amount_cents'];
        if ($opening) {
            $this->assertSame($opening, $data);
        } else {
            DB::table('cajas_v1_custody_openings')->insert([...$data, ...$audit]);
        }

        return $data;
    }

    private function recordMovement(?object $opening, string $action, array $data, array $audit): array
    {
        $own = $action === 'recognize';
        $source = $own ? 'prestamos' : 'cajas';
        $entries = DB::table('cajas_v1_custody_movements');
        $existing = (clone $entries)->where('cash_account_id', $data['cash_account_id'])
            ->where('reference', $data['reference'])->where('source', $source)->first();
        $movement = [
            'cash_account_id' => $data['cash_account_id'], 'reference' => $data['reference'], 'source' => $source,
            'financial_origin' => $own ? $data['financial_type'].':'.$data['financial_id'] : null,
            'effective_at' => $data['effective_at'], 'evidence_ref' => $data['evidence_ref'],
            'status' => $own ? 'confirmed' : $data['status'],
        ];
        if ($existing) {
            $this->assertSame($existing, $own ? $movement : [...$movement, 'amount_cents' => (int) $data['amount_cents']]);

            return [...$movement, 'amount_cents' => $existing->amount_cents];
        }
        if ($own && (! $opening || $data['effective_at'] < $opening->cutoff_at)) {
            throw new RuntimeException('El movimiento propio debe ser posterior a una apertura reconocida.');
        }
        if ($own && (clone $entries)->where('financial_origin', $movement['financial_origin'])->exists()) {
            throw new RuntimeException('El movimiento financiero ya tiene un efecto de custodia.');
        }
        $movement['amount_cents'] = $own ? $this->financialAmount($data) : (int) $data['amount_cents'];
        $entries->insert([...$movement, ...$audit]);

        return $movement;
    }

    private function financialAmount(array $data): int
    {
        $publicId = $data['financial_id'];
        $type = $data['financial_type'];
        if ($type === 'payout' || $type === 'payout_return') {
            return $this->payoutCustodyAmount($data);
        }

        $amount = match ($type) {
            'payment' => CollectionMovement::where('public_id', $publicId)->where('confirmation_status', 'applied')
                ->where('payment_method', 'cash')->where('idempotency_key', 'like', 'cajas-cut-item-%')
                ->where('idempotency_key', 'not like', 'cajas-cut-item-correction-%')->value('contract_amount'),
            'disbursement' => FundDisbursement::where('public_id', $publicId)->where('status', 'registered')
                ->where('is_delivery_date_inferred', false)->value('amount'),
        };
        $cents = Money::cents($amount);
        if ($cents === 0 || abs($cents) > 999999999999999) {
            throw new RuntimeException('No existe un movimiento financiero propio confirmado por ese importe.');
        }

        return $type === 'disbursement' ? -$cents : $cents;
    }

    private function payoutCustodyAmount(array $data): int
    {
        $publicId = $data['financial_id'];
        $isReturn = $data['financial_type'] === 'payout_return';
        $kind = $isReturn ? 'return' : 'payout';
        if ($data['reference'] !== $publicId) {
            throw new RuntimeException('El egreso o la devolución requiere su referencia financiera original.');
        }

        $entry = $this->payouts->appliedCustodyEntry($publicId, $kind)['entry'] ?? null;
        if ($entry === null) {
            throw new RuntimeException('El egreso o la devolución aún no tiene respaldo en un corte aplicado.');
        }

        $date = CarbonImmutable::parse($data['effective_at'])->setTimezone('America/Merida')->toDateString();
        if ($entry['cash_account_id'] !== $data['cash_account_id'] || $entry[$isReturn ? 'received_on' : 'paid_on'] !== $date) {
            throw new RuntimeException('La caja o la fecha efectiva no coincide con el movimiento físico confirmado.');
        }

        return $isReturn ? $entry['amount_cents'] : -$entry['amount_cents'];
    }

    private function recordClose(?object $opening, array $data, array $audit): array
    {
        $account = $data['cash_account_id'];
        $closes = DB::table('cajas_v1_custody_closes');
        $existing = (clone $closes)->where('cash_account_id', $account)->where('close_id', $data['close_id'])->first();
        if ($existing) {
            if (! JsonData::equals(json_decode($existing->request, true, flags: JSON_THROW_ON_ERROR), $data)) {
                throw new RuntimeException('La referencia ya está registrada con otros datos de custodia.');
            }

            return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
        }

        $cutoff = $data['cutoff_at'];
        $reasons = $data['pending_reasons'] ?? [];
        if (! $data['coverage_confirmed']) {
            $reasons[] = 'COVERAGE_NOT_CONFIRMED';
        }
        if (! $opening || $opening->opening_close_id !== $data['opening_close_id']
            || $data['close_id'] === $opening->opening_close_id || $opening->cutoff_at > $cutoff) {
            $opening = null;
            $reasons[] = 'OPENING_UNVERIFIED';
        }

        $entries = DB::table('cajas_v1_custody_movements')->where('cash_account_id', $account)->where('effective_at', '<=', $cutoff)->orderBy('id');
        if ($opening) {
            // A distinct close may share the opening timestamp. Posting order and the evidence identify the later entries.
            $entries->where('effective_at', '>=', $opening->cutoff_at);
        }
        $own = (clone $entries)->where('source', 'prestamos')->where('recorded_at', '<=', $cutoff)->get();
        $observed = (clone $entries)->where('source', 'cajas')->get()->reject(fn ($entry) => $own
            ->contains(fn ($posted) => $posted->reference === $entry->reference && $posted->amount_cents === $entry->amount_cents));
        if ($observed->contains('status', 'pending')) {
            $reasons[] = 'CUSTODY_EVIDENCE_PENDING';
        }
        $inflows = $own->where('amount_cents', '>', 0)->sum('amount_cents');
        $outflows = -$own->where('amount_cents', '<', 0)->sum('amount_cents');
        $expected = $opening ? $opening->amount_cents + $inflows - $outflows : null;
        if ($expected !== null && $expected < 0) {
            $reasons[] = 'NEGATIVE_EXPECTED_CUSTODY';
        }
        $result = [
            'close_id' => $data['close_id'], 'cash_account_id' => $account, 'currency' => 'MXN', 'scope' => self::SCOPE,
            'cutoff_at' => $cutoff, 'opening_close_id' => $data['opening_close_id'],
            'ledger_position' => $own->max('id') ?? 0, 'opening_cents' => $opening?->amount_cents,
            'confirmed_inflows_cents' => $inflows, 'confirmed_outflows_cents' => $outflows,
            'expected_custody_cents' => $reasons === [] ? $expected : null,
            'coverage_status' => $reasons === [] ? 'complete' : 'pending',
            'pending_reasons' => array_values(array_unique($reasons)),
            'movements' => $own->map(fn ($entry) => ['reference' => $entry->reference, 'amount_cents' => $entry->amount_cents])->all(),
            'cajas_only_movements' => $observed->map(fn ($entry) => [
                'reference' => $entry->reference, 'amount_cents' => $entry->amount_cents, 'status' => $entry->status,
            ])->values()->all(),
        ];
        $closes->insert([
            'close_id' => $data['close_id'], 'cash_account_id' => $account, 'request' => json_encode($data, JSON_THROW_ON_ERROR),
            'result' => json_encode($result, JSON_THROW_ON_ERROR), ...$audit,
        ]);

        return $result;
    }

    private function assertSame(object $existing, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if ($existing->{$field} !== $value) {
                throw new RuntimeException('La referencia ya está registrada con otros datos de custodia.');
            }
        }
    }
}
