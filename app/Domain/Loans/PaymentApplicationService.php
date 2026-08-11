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

            $remainingCents = Money::cents($movement->contract_amount);
            $installments = $movement->loan->installments()
                ->where('remaining_amount', '>', 0)
                ->when(
                    $movement->type === 'advance',
                    fn ($query) => $query->orderByDesc('number'),
                    fn ($query) => $query
                        ->when($movement->targetInstallment, fn ($query) => $query->where('number', '>=', $movement->targetInstallment->number))
                        ->orderBy('number'),
                )
                ->lockForUpdate()
                ->get();

            if ($movement->type === 'advance') {
                $advanceAllowed = $this->advanceAllowedAmounts($movement, $installments);
                $this->assertAdvanceCoversAllowedAmounts($remainingCents, $advanceAllowed);
            }

            foreach ($installments as $installment) {
                if ($remainingCents <= 0) {
                    break;
                }

                $installmentRemaining = $movement->type === 'advance'
                    ? min(Money::cents($installment->remaining_amount), $advanceAllowed[$installment->id] ?? 0)
                    : Money::cents($installment->remaining_amount);

                if ($installmentRemaining <= 0) {
                    continue;
                }

                $applied = min($remainingCents, $installmentRemaining);
                $newRemaining = $installmentRemaining - $applied;
                $totalRemainingAfter = Money::cents($installment->remaining_amount) - $applied;
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

                $this->recordInvestorReturns($movement, $installment, $applied, $confirmedByUserId);

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

                foreach ($movement->allocations as $allocation) {
                    $installment = $allocation->installment()->lockForUpdate()->firstOrFail();
                    $allocationCents = Money::cents($allocation->amount);
                    $appliedCents = max(0, Money::cents($installment->applied_amount) - $allocationCents);
                    $remainingCents = Money::cents($installment->remaining_amount) + $allocationCents;
                    $contractCents = $this->operationalCents($installment);

                    $installment->update([
                        'applied_amount' => Money::decimal($appliedCents),
                        'remaining_amount' => Money::decimal(min($contractCents, $remainingCents)),
                        'status' => $appliedCents > 0 ? 'partial' : 'upcoming',
                    ]);
                }

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

    private function statusForCoveredInstallment(string $movementType): string
    {
        return $movementType === 'advance' ? 'advanced' : 'confirmed';
    }

    /**
     * @return array<int, int>
     */
    private function advanceAllowedAmounts(CollectionMovement $movement, $installments): array
    {
        $operatedOn = CarbonImmutable::parse($movement->operated_on, 'America/Merida')->startOfDay();
        $currentMonthEnd = $operatedOn->endOfMonth();
        $allowed = [];

        foreach ($installments as $installment) {
            $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();
            $remainingCents = Money::cents($installment->remaining_amount);
            $contractCents = $this->operationalCents($installment);
            $ratio = $contractCents > 0 ? min(1, $remainingCents / $contractCents) : 0;

            $allowed[$installment->id] = $dueDate->greaterThan($currentMonthEnd)
                ? min($remainingCents, (int) round(Money::cents($installment->principal_amount) * $ratio))
                : $remainingCents;
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

    private function recordInvestorReturns(CollectionMovement $movement, $installment, int $appliedCents, int $userId): void
    {
        $contractCents = $this->operationalCents($installment);

        if ($appliedCents <= 0 || $contractCents <= 0) {
            return;
        }

        if ($movement->type === 'advance') {
            $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();
            $currentMonthEnd = CarbonImmutable::parse($movement->operated_on, 'America/Merida')->endOfMonth();
            $interestCents = $dueDate->greaterThan($currentMonthEnd) ? 0 : (int) round(Money::cents($installment->interest_amount) * min(1, $appliedCents / $contractCents));
            $this->investorReturnRecorder->record($movement->loan, $installment, $appliedCents, $interestCents, $movement, $userId);

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
}
