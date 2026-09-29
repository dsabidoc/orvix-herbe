<?php

namespace App\Domain\Loans;

use App\Models\Installment;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Carbon\CarbonImmutable;

class InstallmentPaymentPolicy
{
    public function isFutureMonth(Installment $installment, CarbonImmutable|string $paymentDate): bool
    {
        $monthEnd = CarbonImmutable::parse($paymentDate, 'America/Merida')->endOfMonth();
        $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida');

        return $dueDate->greaterThan($monthEnd);
    }

    public function principalRemainingCents(Installment $installment): int
    {
        $principalCents = Money::cents($installment->principal_amount);
        $operationalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

        if ($principalCents <= 0 || $operationalCents <= 0) {
            return 0;
        }

        $allocations = $installment->relationLoaded('allocations')
            ? $installment->allocations
            : PaymentAllocation::query()->with('movement:id,type')->where('installment_id', $installment->id)->get();

        $appliedPrincipalCents = $allocations
            ->sum(function (PaymentAllocation $allocation) use ($principalCents, $operationalCents): int {
                $amountCents = Money::cents($allocation->amount);

                if (in_array($allocation->movement?->type, ['advance', 'capital_advance'], true)) {
                    return $amountCents;
                }

                return (int) round($principalCents * min(1, $amountCents / $operationalCents));
            });

        return max(0, $principalCents - $appliedPrincipalCents);
    }

    /** @return array{principal:int,interest:int} */
    public function remainingComponentsCents(Installment $installment): array
    {
        $principalCents = $this->principalRemainingCents($installment);
        $remainingCents = Money::cents($installment->remaining_amount);
        $interestCents = min(
            Money::cents($installment->interest_amount),
            max(0, $remainingCents - $principalCents),
        );

        return [
            'principal' => min($principalCents, $remainingCents),
            'interest' => $interestCents,
        ];
    }
}
