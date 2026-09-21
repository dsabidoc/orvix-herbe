<?php

namespace App\Domain\Collections;

use App\Models\CollectionMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PeriodCollectionService
{
    /**
     * Sums only ordinary payments assigned to installments due in the period.
     * Advances and settlements are intentionally excluded from period collection.
     *
     * @param  Collection<int, int>|array<int, int>  $loanIds
     */
    public function amountForLoans(Collection|array $loanIds, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): int
    {
        $loanIds = collect($loanIds)->filter()->map(fn ($id) => (int) $id)->values();

        if ($loanIds->isEmpty()) {
            return 0;
        }

        return CollectionMovement::query()
            ->with('targetInstallment')
            ->whereIn('loan_id', $loanIds)
            ->where('type', 'ordinary')
            ->whereIn('confirmation_status', ['reported', 'applied'])
            ->whereBetween('operated_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->filter(fn (CollectionMovement $movement) => $movement->targetInstallment
                && CarbonImmutable::parse($movement->targetInstallment->due_date, 'America/Merida')
                    ->startOfDay()
                    ->betweenIncluded($periodStart, $periodEnd))
            ->sum(fn (CollectionMovement $movement) => Money::cents($movement->contract_amount));
    }
}
