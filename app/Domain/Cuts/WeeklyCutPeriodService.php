<?php

namespace App\Domain\Cuts;

use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Operator;
use App\Models\WeeklyCut;
use App\Models\WeeklyCutItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WeeklyCutPeriodService
{
    public const TIMEZONE = 'America/Merida';
    public const REPORTABLE_MOVEMENT_STATUSES = ['reported', 'applied'];

    public static function isReportableMovement(?CollectionMovement $movement): bool
    {
        if ($movement === null || ! in_array($movement->confirmation_status, self::REPORTABLE_MOVEMENT_STATUSES, true)) {
            return false;
        }

        if (! $movement->affects_investors) {
            return false;
        }

        $registeredBy = $movement->relationLoaded('registeredBy')
            ? $movement->registeredBy
            : $movement->registeredBy()->first();

        return (bool) $registeredBy?->hasRole('operador-cartera');
    }

    /**
     * @return array{start:CarbonImmutable,end:CarbonImmutable,settlement:CarbonImmutable}
     */
    public function periodFor(?CarbonInterface $registeredAt = null): array
    {
        $date = CarbonImmutable::instance($registeredAt ?? now(self::TIMEZONE))->timezone(self::TIMEZONE);
        $daysSinceFriday = ($date->dayOfWeek - CarbonInterface::FRIDAY + 7) % 7;
        $start = $date->subDays($daysSinceFriday)->startOfDay();

        return [
            'start' => $start,
            'end' => $start->addDays(6)->endOfDay(),
            'settlement' => $start->addDays(7)->startOfDay(),
        ];
    }

    public function openCutForOperator(Operator|int $operator, ?int $userId = null, ?CarbonInterface $registeredAt = null): WeeklyCut
    {
        $operatorId = $operator instanceof Operator ? $operator->id : $operator;
        $period = $this->periodFor($registeredAt);

        return DB::transaction(function () use ($operatorId, $userId, $period) {
            $cut = WeeklyCut::query()
                ->where('operator_id', $operatorId)
                ->whereDate('period_starts_on', $period['start']->toDateString())
                ->whereDate('period_ends_on', $period['end']->toDateString())
                ->lockForUpdate()
                ->first();

            if ($cut) {
                return $cut;
            }

            $cut = WeeklyCut::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $operatorId,
                'submitted_by' => $userId,
                'period_starts_on' => $period['start']->toDateString(),
                'period_ends_on' => $period['end']->toDateString(),
                'settlement_on' => $period['settlement']->toDateString(),
                'expected_total' => Money::decimal($this->expectedCents($operatorId, $period['start'], $period['end'])),
                'reported_total' => '0.00',
                'received_total' => '0.00',
                'confirmed_total' => '0.00',
                'difference_total' => '0.00',
                'previous_balance' => Money::decimal($this->latestOperatorBalance($operatorId)),
                'status' => 'forming',
                'submitted_at' => now(self::TIMEZONE),
            ]);

            AuditEvent::query()->create([
                'user_id' => $userId,
                'action' => 'weekly_cut.created',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'after' => [
                    'operator_id' => $operatorId,
                    'period_starts_on' => $cut->period_starts_on->toDateString(),
                    'period_ends_on' => $cut->period_ends_on->toDateString(),
                    'settlement_on' => $cut->settlement_on?->toDateString(),
                ],
            ]);

            return $cut;
        });
    }

    public function createCutForOperator(Operator|int $operator, ?int $userId = null, ?CarbonInterface $cutDate = null): WeeklyCut
    {
        $operatorId = $operator instanceof Operator ? $operator->id : $operator;
        $date = CarbonImmutable::instance($cutDate ?? now(self::TIMEZONE))->timezone(self::TIMEZONE);
        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();

        return DB::transaction(function () use ($operatorId, $userId, $dayStart, $dayEnd) {
            $cut = WeeklyCut::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $operatorId,
                'submitted_by' => $userId,
                'period_starts_on' => $dayStart->toDateString(),
                'period_ends_on' => $dayStart->toDateString(),
                'settlement_on' => $dayStart->toDateString(),
                'expected_total' => Money::decimal($this->expectedCents($operatorId, $dayStart, $dayEnd)),
                'reported_total' => '0.00',
                'received_total' => '0.00',
                'confirmed_total' => '0.00',
                'difference_total' => '0.00',
                'previous_balance' => Money::decimal($this->latestOperatorBalance($operatorId)),
                'status' => 'forming',
                'submitted_at' => now(self::TIMEZONE),
            ]);

            AuditEvent::query()->create([
                'user_id' => $userId,
                'action' => 'weekly_cut.created',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'after' => [
                    'operator_id' => $operatorId,
                    'cut_date' => $cut->period_starts_on->toDateString(),
                    'submitted_at' => $cut->submitted_at?->toDateTimeString(),
                ],
            ]);

            return $cut;
        });
    }

    public function attachMovement(CollectionMovement $movement, ?int $userId = null): WeeklyCut
    {
        return DB::transaction(function () use ($movement, $userId) {
            $movement = CollectionMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $registeredAt = $movement->registered_at ?? $movement->created_at ?? now(self::TIMEZONE);
            $cut = $this->openCutForOperator($movement->operator_id, $userId ?? $movement->registered_by, $registeredAt);

            abort_if($cut->status === 'closed', 422, 'No se pueden registrar cobros en un corte cerrado.');
            abort_unless(self::isReportableMovement($movement), 422, 'Solo los cobros pagados vigentes pueden agregarse al corte.');

            if ($movement->weekly_cut_id && $movement->weekly_cut_id !== $cut->id) {
                return $movement->weeklyCut()->firstOrFail();
            }

            $movement->update([
                'weekly_cut_id' => $cut->id,
                'registered_at' => $registeredAt,
            ]);

            WeeklyCutItem::query()->firstOrCreate(
                [
                    'weekly_cut_id' => $cut->id,
                    'collection_movement_id' => $movement->id,
                ],
                [
                    'expected_amount' => $movement->contract_amount,
                    'reported_amount' => Money::decimal($this->movementTotalCents($movement)),
                    'received_amount' => '0.00',
                    'status' => 'included',
                ],
            );

            $this->refreshTotals($cut->fresh());

            AuditEvent::query()->create([
                'user_id' => $userId ?? $movement->registered_by,
                'action' => 'collection_movement.assigned_to_cut',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'weekly_cut_id' => $cut->id,
                    'registered_at' => CarbonImmutable::parse($registeredAt, self::TIMEZONE)->toDateTimeString(),
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $cut;
        });
    }

    public function attachMovementToCut(CollectionMovement $movement, WeeklyCut $cut, ?int $userId = null): WeeklyCut
    {
        return DB::transaction(function () use ($movement, $cut, $userId) {
            $movement = CollectionMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();

            abort_if($cut->status === 'closed', 422, 'No se pueden registrar cobros en un corte cerrado.');
            abort_if($cut->operator_id !== $movement->operator_id, 422, 'El cobro no pertenece al operador de este corte.');
            abort_unless(self::isReportableMovement($movement), 422, 'Solo los cobros pagados vigentes pueden agregarse al corte.');

            $registeredAt = $movement->registered_at ?? $movement->created_at ?? now(self::TIMEZONE);

            if ($movement->weekly_cut_id && $movement->weekly_cut_id !== $cut->id) {
                WeeklyCutItem::query()
                    ->where('weekly_cut_id', $movement->weekly_cut_id)
                    ->where('collection_movement_id', $movement->id)
                    ->delete();
            }

            $movement->update([
                'weekly_cut_id' => $cut->id,
                'registered_at' => $registeredAt,
            ]);

            WeeklyCutItem::query()->firstOrCreate(
                [
                    'weekly_cut_id' => $cut->id,
                    'collection_movement_id' => $movement->id,
                ],
                [
                    'expected_amount' => $movement->contract_amount,
                    'reported_amount' => Money::decimal($this->movementTotalCents($movement)),
                    'received_amount' => '0.00',
                    'status' => 'included',
                ],
            );

            $this->refreshTotals($cut->fresh());

            AuditEvent::query()->create([
                'user_id' => $userId ?? $movement->registered_by,
                'action' => 'collection_movement.assigned_to_selected_cut',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'weekly_cut_id' => $cut->id,
                    'registered_at' => CarbonImmutable::parse($registeredAt, self::TIMEZONE)->toDateTimeString(),
                    'assignment_reason' => 'admin_overdue_correction_from_cut',
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $cut->fresh();
        });
    }

    public function refreshTotals(WeeklyCut $cut): WeeklyCut
    {
        $cut = WeeklyCut::query()->whereKey($cut->id)->with('items.movement')->firstOrFail();
        $periodStart = CarbonImmutable::parse($cut->period_starts_on, self::TIMEZONE)->startOfDay();
        $periodEnd = CarbonImmutable::parse($cut->period_ends_on, self::TIMEZONE)->endOfDay();
        $reportedCents = $cut->items
            ->filter(fn (WeeklyCutItem $item) => self::isReportableMovement($item->movement))
            ->sum(fn (WeeklyCutItem $item) => Money::cents($item->reported_amount));
        $receivedCents = Money::cents($cut->received_total);
        $confirmedCents = $receivedCents;
        $fundsCents = $cut->fundDisbursements()->where('status', 'registered')->sum('amount') * 100;
        $adjustmentsInCents = $cut->ledgerEntries()->whereIn('type', ['regularization', 'overage'])->sum('amount') * 100;
        $adjustmentsOutCents = $cut->ledgerEntries()->whereIn('type', ['shortfall'])->sum('amount') * 100;
        $previousBalanceCents = $this->latestOperatorBalanceBeforeCut($cut);
        $netCents = (int) round($confirmedCents + $adjustmentsInCents - $fundsCents - $adjustmentsOutCents);
        $differenceCents = $confirmedCents - $reportedCents;
        $balanceAfterCents = $previousBalanceCents + $confirmedCents - $reportedCents;

        $cut->forceFill([
            'expected_total' => Money::decimal($this->expectedCents($cut->operator_id, $periodStart, $periodEnd)),
            'reported_total' => Money::decimal((int) $reportedCents),
            'confirmed_total' => Money::decimal((int) $confirmedCents),
            'funds_delivered_total' => Money::decimal((int) round($fundsCents)),
            'adjustments_in_total' => Money::decimal((int) round($adjustmentsInCents)),
            'adjustments_out_total' => Money::decimal((int) round($adjustmentsOutCents)),
            'net_result_total' => Money::decimal($netCents),
            'difference_total' => Money::decimal((int) $differenceCents),
            'previous_balance' => Money::decimal($previousBalanceCents),
            'accumulated_balance' => Money::decimal($balanceAfterCents),
        ])->save();

        return $cut->fresh();
    }

    private function expectedCents(int $operatorId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round(Installment::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where('remaining_amount', '>', 0)
            ->whereHas('loan', fn ($query) => $query
                ->where('operator_id', $operatorId)
                ->where('status', 'active')
                ->where('is_frozen', false))
            ->sum('remaining_amount') * 100);
    }

    private function movementTotalCents(CollectionMovement $movement): int
    {
        return Money::cents($movement->contract_amount)
            + Money::cents($movement->operator_surcharge_amount)
            + Money::cents($movement->external_concepts_amount)
            + Money::cents($movement->additional_charge_amount ?? 0)
            + Money::cents($movement->delinquency_amount ?? 0);
    }

    private function latestOperatorBalance(int $operatorId): int
    {
        $latestBalance = DB::table('operator_ledger_entries')
            ->where('operator_id', $operatorId)
            ->latest('id')
            ->value('balance_after');

        return Money::cents($latestBalance);
    }

    private function latestOperatorBalanceBeforeCut(WeeklyCut $cut): int
    {
        $latestBalance = DB::table('operator_ledger_entries')
            ->where('operator_id', $cut->operator_id)
            ->where(function ($query) use ($cut) {
                $query->whereNull('weekly_cut_id')->orWhere('weekly_cut_id', '!=', $cut->id);
            })
            ->latest('id')
            ->value('balance_after');

        return Money::cents($latestBalance);
    }
}
