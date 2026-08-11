<?php

namespace App\Domain\Loans;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Investors\InvestorReturnRecorder;
use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\PaymentAllocation;
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
            $contractCents = Money::cents($installment->contract_amount);
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
        $contractCents = Money::cents($installment->contract_amount);

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
}
