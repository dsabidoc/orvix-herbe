<?php

namespace App\Domain\Loans;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Investors\InvestorReturnRecorder;
use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\PaymentAllocation;
use App\Models\WeeklyCutItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentApplicationService
{
    public function __construct(
        private readonly InvestorReturnRecorder $investorReturnRecorder,
        private readonly WeeklyCutPeriodService $cutPeriodService,
    ) {}

    public function confirm(CollectionMovement $movement, int $confirmedByUserId): CollectionMovement
    {
        return DB::transaction(function () use ($movement, $confirmedByUserId) {
            $movement = CollectionMovement::query()
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($movement->confirmation_status !== 'reported') {
                throw new RuntimeException('Este movimiento ya no esta pendiente de confirmacion.');
            }

            if ($movement->type === 'settlement') {
                throw new RuntimeException('La liquidacion se aplica desde el boton Liquidar credito para calcular solo capital futuro e interes del mes corriente.');
            }

            if ($movement->type === 'advance' && ($movement->loan->calculation_method ?? 'regular') === 'interest_only') {
                return $this->applyInterestOnlyCapitalAdvance($movement, $confirmedByUserId);
            }

            $remainingCents = Money::cents($movement->contract_amount);
            $installments = $movement->loan->installments()
                ->where('remaining_amount', '>', 0)
                ->when($this->isDirectCapitalAdvance($movement), fn ($query) => $query->whereKey($movement->target_installment_id))
                ->when(
                    $movement->type === 'advance',
                    fn ($query) => $query->orderByDesc('number'),
                    fn ($query) => $query
                        ->when($movement->targetInstallment, fn ($query) => $query->where('number', '>=', $movement->targetInstallment->number))
                        ->orderBy('number'),
                )
                ->lockForUpdate()
                ->get();

            $advanceAllowed = [];
            if ($this->isCapitalAdvance($movement)) {
                $advanceAllowed = $this->advanceAllowedAmounts($movement, $installments);
            }

            if ($movement->type === 'advance') {
                $this->assertAdvanceCoversAllowedAmounts($remainingCents, $advanceAllowed);
            } elseif ($this->isDirectCapitalAdvance($movement)) {
                $this->assertDirectCapitalAdvanceAmount($remainingCents, $advanceAllowed);
            }

            foreach ($installments as $installment) {
                if ($remainingCents <= 0) {
                    break;
                }

                $installmentRemaining = $this->isCapitalAdvance($movement)
                    ? ($advanceAllowed[$installment->id] ?? 0)
                    : Money::cents($installment->remaining_amount);

                if ($installmentRemaining <= 0) {
                    continue;
                }

                $applied = min($remainingCents, $installmentRemaining);
                $totalRemainingAfter = $this->isCapitalAdvance($movement) && $applied === $installmentRemaining
                    ? 0
                    : Money::cents($installment->remaining_amount) - $applied;
                $newApplied = Money::cents($installment->applied_amount) + $applied;

                $installment->update([
                    'applied_amount' => Money::decimal($newApplied),
                    'remaining_amount' => Money::decimal($totalRemainingAfter),
                    'status' => $totalRemainingAfter === 0 ? $this->statusForCoveredInstallment($movement->type) : 'partial',
                ]);

                PaymentAllocation::query()->create([
                    'collection_movement_id' => $movement->id,
                    'installment_id' => $installment->id,
                    'amount' => Money::decimal($applied),
                ]);

                if ($movement->affects_investors) {
                    $this->recordInvestorReturns($movement, $installment, $applied, $confirmedByUserId);
                }

                $remainingCents -= $applied;
            }

            if ($remainingCents > 0) {
                throw new RuntimeException('El monto excede el saldo contractual disponible.');
            }

            $movement->update([
                'confirmation_status' => 'applied',
                'confirmed_by' => $confirmedByUserId,
                'confirmed_at' => now('America/Merida'),
            ]);

            $this->markLoanSettledIfFullyPaid($movement, $confirmedByUserId);

            if ($movement->weekly_cut_id) {
                $this->cutPeriodService->refreshTotals($movement->weeklyCut()->first());
            }

            AuditEvent::query()->create([
                'user_id' => $confirmedByUserId,
                'action' => 'collection_movement.confirmed',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'folio' => $movement->folio,
                    'type' => $movement->type,
                    'contract_amount' => $movement->contract_amount,
                    'allocations' => $movement->allocations()->count(),
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $movement->fresh(['loan.client', 'allocations.installment']);
        });
    }

    public function reverse(CollectionMovement $movement, int $reversedByUserId): CollectionMovement
    {
        return DB::transaction(function () use ($movement, $reversedByUserId) {
            $movement = CollectionMovement::query()
                ->with(['weeklyCut', 'allocations.installment'])
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($movement->confirmation_status, ['reported', 'applied'], true)) {
                throw new RuntimeException('Este cobro ya no puede regresarse a pendiente.');
            }

            if ($movement->weeklyCut?->status === 'closed') {
                throw new RuntimeException('No se puede regresar a pendiente un cobro de un corte cerrado.');
            }

            if ($movement->type === 'settlement') {
                throw new RuntimeException('Las liquidaciones deben ajustarse desde el flujo de liquidacion.');
            }

            if ($movement->confirmation_status === 'applied') {
                $this->reverseInvestorReturns($movement, $reversedByUserId);

                if ($movement->type === 'advance' && ($movement->loan->calculation_method ?? 'regular') === 'interest_only') {
                    return $this->reverseInterestOnlyCapitalAdvance($movement, $reversedByUserId);
                }

                foreach ($movement->allocations as $allocation) {
                    $installment = $allocation->installment()->lockForUpdate()->firstOrFail();
                    $allocationCents = Money::cents($allocation->amount);
                    $appliedCents = max(0, Money::cents($installment->applied_amount) - $allocationCents);
                    $contractCents = $this->operationalCents($installment);
                    $remainingCents = $this->isCapitalAdvance($movement)
                        ? $contractCents
                        : Money::cents($installment->remaining_amount) + $allocationCents;

                    $installment->update([
                        'applied_amount' => Money::decimal($appliedCents),
                        'remaining_amount' => Money::decimal(min($contractCents, $remainingCents)),
                        'status' => $appliedCents > 0 ? 'partial' : 'upcoming',
                    ]);
                }

                $this->markLoanActiveIfScheduleReopened($movement, $reversedByUserId);

                $movement->allocations()->delete();
            }

            $cut = $movement->weeklyCut;
            WeeklyCutItem::query()
                ->where('collection_movement_id', $movement->id)
                ->delete();

            $movement->update([
                'confirmation_status' => 'reversed',
                'weekly_cut_id' => null,
                'origin_weekly_cut_id' => null,
                'notes' => trim((string) $movement->notes."\nRevertido por administracion el ".now('America/Merida')->format('d/m/Y H:i')),
            ]);

            if ($cut) {
                $this->cutPeriodService->refreshTotals($cut);
            }

            AuditEvent::query()->create([
                'user_id' => $reversedByUserId,
                'action' => 'collection_movement.reversed',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'folio' => $movement->folio,
                    'type' => $movement->type,
                    'target_installment_id' => $movement->target_installment_id,
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $movement->fresh(['loan.client']);
        });
    }

    public function reverseSettlement(CollectionMovement $movement, int $reversedByUserId): CollectionMovement
    {
        return DB::transaction(function () use ($movement, $reversedByUserId) {
            $movement = CollectionMovement::query()
                ->with(['weeklyCut', 'allocations.installment', 'loan'])
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($movement->type !== 'settlement' || $movement->confirmation_status !== 'applied') {
                throw new RuntimeException('Esta liquidacion ya no se puede cancelar.');
            }

            if ($movement->weeklyCut?->status === 'closed') {
                throw new RuntimeException('No se puede cancelar una liquidacion ligada a un corte cerrado.');
            }

            $loan = $movement->loan()->lockForUpdate()->firstOrFail();

            $this->reverseInvestorReturns($movement, $reversedByUserId);

            foreach ($movement->allocations as $allocation) {
                $installment = $allocation->installment()->lockForUpdate()->firstOrFail();
                $allocationCents = Money::cents($allocation->amount);
                $appliedCents = max(0, Money::cents($installment->applied_amount) - $allocationCents);
                $contractCents = $this->operationalCents($installment);
                $remainingCents = max(0, $contractCents - $appliedCents);

                $installment->update([
                    'applied_amount' => Money::decimal($appliedCents),
                    'remaining_amount' => Money::decimal($remainingCents),
                    'status' => $remainingCents === 0
                        ? 'confirmed'
                        : ($appliedCents > 0 ? 'partial' : 'upcoming'),
                ]);
            }

            $cut = $movement->weeklyCut;

            $movement->allocations()->delete();
            WeeklyCutItem::query()
                ->where('collection_movement_id', $movement->id)
                ->delete();

            $movement->update([
                'confirmation_status' => 'reversed',
                'weekly_cut_id' => null,
                'origin_weekly_cut_id' => null,
                'notes' => trim((string) $movement->notes."\nLiquidacion cancelada por administracion el ".now('America/Merida')->format('d/m/Y H:i')),
            ]);

            $loan->update([
                'status' => 'active',
                'settlement_reason' => null,
                'settled_at' => null,
                'settled_by' => null,
            ]);

            if ($cut) {
                $this->cutPeriodService->refreshTotals($cut);
            }

            AuditEvent::query()->create([
                'user_id' => $reversedByUserId,
                'action' => 'loan_settlement.reversed',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'folio' => $movement->folio,
                    'loan_id' => $loan->id,
                    'allocations_reversed' => $movement->allocations->count(),
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $movement->fresh(['loan.client']);
        });
    }

    private function statusForCoveredInstallment(string $movementType): string
    {
        return in_array($movementType, ['advance', 'capital_advance'], true) ? 'advanced' : 'confirmed';
    }

    private function applyInterestOnlyCapitalAdvance(CollectionMovement $movement, int $confirmedByUserId): CollectionMovement
    {
        $loan = $movement->loan()->with('installments')->lockForUpdate()->firstOrFail();
        $advanceCents = Money::cents($movement->contract_amount);

        if ($advanceCents <= 0) {
            throw new RuntimeException('El abono a capital debe ser mayor a cero.');
        }

        $anchor = $loan->installments()
            ->where('remaining_amount', '>', 0)
            ->orderBy('number')
            ->lockForUpdate()
            ->first();

        if (! $anchor) {
            throw new RuntimeException('Este prestamo no tiene letras pendientes para aplicar el abono.');
        }

        $currentCapitalCents = $this->interestOnlyCurrentCapitalCents($loan);

        if ($advanceCents > $currentCapitalCents) {
            throw new RuntimeException('El abono a capital excede el capital vivo del prestamo.');
        }

        PaymentAllocation::query()->create([
            'collection_movement_id' => $movement->id,
            'installment_id' => $anchor->id,
            'amount' => Money::decimal($advanceCents),
        ]);

        if ($movement->affects_investors) {
            $this->investorReturnRecorder->record($loan, $anchor, $advanceCents, 0, $movement, $confirmedByUserId);
        }

        $this->refreshInterestOnlyFutureInstallments($loan, max(0, $currentCapitalCents - $advanceCents), $movement->operated_on);

        $movement->update([
            'confirmation_status' => 'applied',
            'confirmed_by' => $confirmedByUserId,
            'confirmed_at' => now('America/Merida'),
        ]);

        if ($movement->weekly_cut_id) {
            $this->cutPeriodService->refreshTotals($movement->weeklyCut()->first());
        }

        AuditEvent::query()->create([
            'user_id' => $confirmedByUserId,
            'action' => 'collection_movement.interest_only_capital_advance_confirmed',
            'auditable_type' => CollectionMovement::class,
            'auditable_id' => $movement->id,
            'after' => [
                'folio' => $movement->folio,
                'loan_id' => $loan->id,
                'capital_before' => Money::decimal($currentCapitalCents),
                'capital_after' => Money::decimal(max(0, $currentCapitalCents - $advanceCents)),
            ],
            'related_idempotency_key' => $movement->idempotency_key,
        ]);

        return $movement->fresh(['loan.client', 'allocations.installment']);
    }

    private function reverseInterestOnlyCapitalAdvance(CollectionMovement $movement, int $reversedByUserId): CollectionMovement
    {
        $loan = $movement->loan()->with('installments')->lockForUpdate()->firstOrFail();
        $advanceCents = $movement->allocations->sum(fn ($allocation) => Money::cents($allocation->amount));
        $currentCapitalCents = $this->interestOnlyCurrentCapitalCents($loan);
        $cut = $movement->weeklyCut;

        $this->refreshInterestOnlyFutureInstallments($loan, min(Money::cents($loan->capital), $currentCapitalCents + $advanceCents), $movement->operated_on);

        $movement->allocations()->delete();
        WeeklyCutItem::query()
            ->where('collection_movement_id', $movement->id)
            ->delete();

        $movement->update([
            'confirmation_status' => 'reversed',
            'weekly_cut_id' => null,
            'origin_weekly_cut_id' => null,
            'notes' => trim((string) $movement->notes."\nRevertido por administracion el ".now('America/Merida')->format('d/m/Y H:i')),
        ]);

        if ($cut) {
            $this->cutPeriodService->refreshTotals($cut);
        }

        AuditEvent::query()->create([
            'user_id' => $reversedByUserId,
            'action' => 'collection_movement.interest_only_capital_advance_reversed',
            'auditable_type' => CollectionMovement::class,
            'auditable_id' => $movement->id,
            'after' => [
                'folio' => $movement->folio,
                'loan_id' => $loan->id,
                'capital_before' => Money::decimal($currentCapitalCents),
                'capital_after' => Money::decimal(min(Money::cents($loan->capital), $currentCapitalCents + $advanceCents)),
            ],
            'related_idempotency_key' => $movement->idempotency_key,
        ]);

        return $movement->fresh(['loan.client']);
    }

    private function interestOnlyCurrentCapitalCents($loan): int
    {
        $openInstallment = $loan->installments()
            ->where('remaining_amount', '>', 0)
            ->orderBy('number')
            ->first();

        return Money::cents($openInstallment?->capital_balance ?? $loan->capital);
    }

    private function refreshInterestOnlyFutureInstallments($loan, int $capitalCents, $effectiveOn): void
    {
        $effectiveDate = CarbonImmutable::parse($effectiveOn, 'America/Merida')->toDateString();
        $interestCents = $capitalCents > 0
            ? (int) round(Money::cents($loan->capital) * (float) $loan->monthly_rate)
            : 0;
        $administrationFeeCents = Money::cents($loan->administration_fee ?? 0);
        $vatRate = $loan->vat_enabled ? 0.16 : 0.0;
        $interestVatCents = (int) round(($interestCents + $administrationFeeCents) * $vatRate);
        $contractCents = $capitalCents > 0 ? $interestCents + $interestVatCents + $administrationFeeCents : 0;
        $operationalCents = $capitalCents > 0 ? $interestCents : 0;

        $loan->installments()
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->orderBy('number')
            ->lockForUpdate()
            ->get()
            ->each(function ($installment) use ($effectiveDate, $capitalCents, $interestCents, $interestVatCents, $contractCents, $operationalCents) {
                $updates = [
                    'capital_balance' => Money::decimal($capitalCents),
                ];

                if ($installment->due_date->toDateString() > $effectiveDate) {
                    $updates += [
                        'contract_amount' => Money::decimal($contractCents),
                        'principal_amount' => '0.00',
                        'interest_amount' => Money::decimal($interestCents),
                        'interest_vat_amount' => Money::decimal($interestVatCents),
                        'applied_amount' => '0.00',
                        'remaining_amount' => Money::decimal($operationalCents),
                        'status' => $capitalCents > 0 ? 'upcoming' : 'advanced',
                    ];
                }

                $installment->update($updates);
            });
    }

    /**
     * @return array<int, int>
     */
    private function advanceAllowedAmounts(CollectionMovement $movement, $installments): array
    {
        $allowed = [];

        foreach ($installments as $installment) {
            $remainingCents = Money::cents($installment->remaining_amount);
            $contractCents = $this->operationalCents($installment);
            $ratio = $contractCents > 0 ? min(1, $remainingCents / $contractCents) : 0;
            $principalCents = (int) round(Money::cents($installment->principal_amount) * $ratio);

            $allowed[$installment->id] = min($remainingCents, $principalCents);
        }

        return array_filter($allowed, fn (int $amount) => $amount > 0);
    }

    /**
     * @param  array<int, int>  $allowedAmounts
     */
    private function assertAdvanceCoversAllowedAmounts(int $amountCents, array $allowedAmounts): void
    {
        $runningCents = 0;

        foreach ($allowedAmounts as $allowedCents) {
            $runningCents += $allowedCents;

            if ($runningCents === $amountCents) {
                return;
            }

            if ($runningCents > $amountCents) {
                break;
            }
        }

        throw new RuntimeException('El abono a capital debe liquidar cuotas completas desde la ultima letra; no se permiten abonos parciales arbitrarios.');
    }

    /**
     * @param  array<int, int>  $allowedAmounts
     */
    private function assertDirectCapitalAdvanceAmount(int $amountCents, array $allowedAmounts): void
    {
        if ($amountCents === array_sum($allowedAmounts)) {
            return;
        }

        throw new RuntimeException('El abono a capital de esta letra debe cubrir exactamente el abono a capital pendiente.');
    }

    private function recordInvestorReturns(CollectionMovement $movement, $installment, int $appliedCents, int $userId): void
    {
        $contractCents = $this->operationalCents($installment);

        if ($appliedCents <= 0 || $contractCents <= 0) {
            return;
        }

        if ($this->isCapitalAdvance($movement)) {
            $this->investorReturnRecorder->record($movement->loan, $installment, $appliedCents, 0, $movement, $userId);

            return;
        }

        $paidRatio = min(1, $appliedCents / $contractCents);
        $principalCents = (int) round(Money::cents($installment->principal_amount) * $paidRatio);
        $interestCents = (int) round(Money::cents($installment->interest_amount) * $paidRatio);

        $this->investorReturnRecorder->record($movement->loan, $installment, $principalCents, $interestCents, $movement, $userId);
    }

    private function operationalCents($installment): int
    {
        $operationalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

        return $operationalCents > 0 ? $operationalCents : Money::cents($installment->contract_amount);
    }

    private function isCapitalAdvance(CollectionMovement $movement): bool
    {
        return in_array($movement->type, ['advance', 'capital_advance'], true);
    }

    private function isDirectCapitalAdvance(CollectionMovement $movement): bool
    {
        return $movement->type === 'capital_advance' && filled($movement->target_installment_id);
    }

    private function reverseInvestorReturns(CollectionMovement $movement, int $userId): void
    {
        $returnMovements = InvestorCapitalMovement::query()
            ->where('type', 'payment_returns_recorded')
            ->where('metadata->collection_movement_id', $movement->id)
            ->get();

        foreach ($returnMovements as $returnMovement) {
            $investor = Investor::query()->whereKey($returnMovement->investor_id)->lockForUpdate()->first();

            if (! $investor) {
                continue;
            }

            $returnedCapitalCents = Money::cents($returnMovement->metadata['returned_capital'] ?? 0);
            $generatedInterestCents = Money::cents($returnMovement->metadata['generated_interest'] ?? 0);

            if (
                Money::cents($investor->returned_capital_balance) < $returnedCapitalCents
                || Money::cents($investor->generated_interest_balance) < $generatedInterestCents
            ) {
                throw new RuntimeException('No se puede revertir: los retornos de inversionista de este pago ya fueron usados o reinvertidos.');
            }

            $investor->forceFill([
                'returned_capital_balance' => Money::decimal(Money::cents($investor->returned_capital_balance) - $returnedCapitalCents),
                'generated_interest_balance' => Money::decimal(Money::cents($investor->generated_interest_balance) - $generatedInterestCents),
            ])->save();

            InvestorCapitalMovement::query()->create([
                'public_id' => (string) str()->ulid(),
                'investor_id' => $investor->id,
                'loan_id' => $returnMovement->loan_id,
                'investment_id' => $returnMovement->investment_id,
                'created_by' => $userId,
                'type' => 'payment_returns_reversed',
                'amount' => Money::decimal(Money::cents($returnMovement->amount)),
                'balance_before' => $investor->available_capital,
                'balance_after' => $investor->available_capital,
                'notes' => 'Retornos revertidos por pago regresado a pendiente '.$movement->folio,
                'metadata' => [
                    'collection_movement_id' => $movement->id,
                    'reversed_return_movement_id' => $returnMovement->id,
                    'returned_capital' => Money::decimal($returnedCapitalCents),
                    'generated_interest' => Money::decimal($generatedInterestCents),
                ],
            ]);
        }
    }

    private function markLoanSettledIfFullyPaid(CollectionMovement $movement, int $userId): void
    {
        $loan = $movement->loan()->lockForUpdate()->first();

        if (! $loan || $loan->status !== 'active' || ($loan->calculation_method ?? 'regular') === 'interest_only') {
            return;
        }

        $hasPendingInstallments = $loan->installments()
            ->where('remaining_amount', '>', 0)
            ->exists();

        if ($hasPendingInstallments) {
            return;
        }

        $loan->update([
            'status' => 'settled',
            'settlement_reason' => 'calendario_pagado',
            'settled_at' => now('America/Merida'),
            'settled_by' => $userId,
        ]);

        AuditEvent::query()->create([
            'user_id' => $userId,
            'action' => 'loan_settled_by_full_schedule',
            'auditable_type' => $loan::class,
            'auditable_id' => $loan->id,
            'after' => [
                'folio' => $loan->folio,
                'settlement_reason' => 'calendario_pagado',
            ],
            'related_idempotency_key' => $movement->idempotency_key,
        ]);
    }

    private function markLoanActiveIfScheduleReopened(CollectionMovement $movement, int $userId): void
    {
        $loan = $movement->loan()->lockForUpdate()->first();

        if (! $loan || $loan->status !== 'settled' || $loan->settlement_reason !== 'calendario_pagado') {
            return;
        }

        $hasPendingInstallments = $loan->installments()
            ->where('remaining_amount', '>', 0)
            ->exists();

        if (! $hasPendingInstallments) {
            return;
        }

        $loan->update([
            'status' => 'active',
            'settlement_reason' => null,
            'settled_at' => null,
            'settled_by' => null,
        ]);

        AuditEvent::query()->create([
            'user_id' => $userId,
            'action' => 'loan_reopened_by_payment_reverse',
            'auditable_type' => $loan::class,
            'auditable_id' => $loan->id,
            'after' => [
                'folio' => $loan->folio,
            ],
            'related_idempotency_key' => $movement->idempotency_key,
        ]);
    }
}
