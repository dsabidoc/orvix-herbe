<?php

namespace App\Domain\Investors;

use App\Models\CollectionMovement;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;

class InvestorMonthlyReturnReport
{
    private const RETURN_MOVEMENT_SIGNS = [
        'payment_returns_recorded' => 1,
        'payment_returns_reversed' => -1,
        'returns_recorded' => 1,
    ];

    /**
     * Builds a read-only comparison between the contractual monthly return and
     * the returns registered from collections during the selected month.
     */
    public function build(Investor $investor, ?string $requestedMonth): array
    {
        $month = $this->resolveMonth($requestedMonth);
        $monthEnd = $month->endOfMonth();
        $expectedCapitalCents = 0;
        $expectedInterestCents = 0;
        $expectedInstallments = 0;

        foreach ($investor->investments as $investment) {
            $loan = $investment->loan;
            $loanCapitalCents = Money::cents($loan?->capital);

            if (
                ! $loan
                || $loanCapitalCents <= 0
                || ! $this->coversMonth($investment, $month, $monthEnd)
                || ($loan->settled_at && $loan->settled_at->toDateString() < $month->toDateString())
            ) {
                continue;
            }

            $capitalShareRate = Money::cents($investment->amount) / $loanCapitalCents;
            $interestShareRate = (float) $investment->investor_share_rate;

            foreach ($loan->installments as $installment) {
                if ($installment->due_date->format('Y-m') !== $month->format('Y-m')) {
                    continue;
                }

                $expectedCapitalCents += (int) round(Money::cents($installment->principal_amount) * $capitalShareRate);
                $expectedInterestCents += (int) round(Money::cents($installment->interest_amount) * $interestShareRate);
                $expectedInstallments++;
            }
        }

        $returnMovements = InvestorCapitalMovement::query()
            ->where('investor_id', $investor->id)
            ->whereIn('type', array_keys(self::RETURN_MOVEMENT_SIGNS))
            ->get();
        $collectionMovementIds = $returnMovements
            ->map(fn ($movement) => (int) data_get($movement->metadata, 'collection_movement_id', 0))
            ->filter()
            ->unique()
            ->values();
        $collectionDates = $collectionMovementIds->isEmpty()
            ? collect()
            : CollectionMovement::query()
                ->whereIn('id', $collectionMovementIds)
                ->pluck('operated_on', 'id');
        $actualCapitalCents = 0;
        $actualInterestCents = 0;
        $actualReturns = 0;

        foreach ($returnMovements as $movement) {
            $collectionMovementId = (int) data_get($movement->metadata, 'collection_movement_id', 0);
            $operatedOn = $collectionDates->get($collectionMovementId) ?? $movement->created_at;

            if (CarbonImmutable::parse($operatedOn, 'America/Merida')->format('Y-m') !== $month->format('Y-m')) {
                continue;
            }

            $sign = self::RETURN_MOVEMENT_SIGNS[$movement->type];
            $actualCapitalCents += $sign * Money::cents(data_get($movement->metadata, 'returned_capital', 0));
            $actualInterestCents += $sign * Money::cents(data_get($movement->metadata, 'generated_interest', 0));
            $actualReturns++;
        }

        return [
            'month' => $month,
            'expected_capital_cents' => $expectedCapitalCents,
            'expected_interest_cents' => $expectedInterestCents,
            'expected_total_cents' => $expectedCapitalCents + $expectedInterestCents,
            'expected_installments' => $expectedInstallments,
            'actual_capital_cents' => $actualCapitalCents,
            'actual_interest_cents' => $actualInterestCents,
            'actual_total_cents' => $actualCapitalCents + $actualInterestCents,
            'actual_returns' => $actualReturns,
        ];
    }

    private function resolveMonth(?string $requestedMonth): CarbonImmutable
    {
        if (is_string($requestedMonth) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth)) {
            return CarbonImmutable::createFromFormat('!Y-m', $requestedMonth, 'America/Merida')->startOfMonth();
        }

        return CarbonImmutable::now('America/Merida')->startOfMonth();
    }

    private function coversMonth(object $investment, CarbonImmutable $month, CarbonImmutable $monthEnd): bool
    {
        return (! $investment->starts_on || $investment->starts_on->toDateString() <= $monthEnd->toDateString())
            && (! $investment->ends_on || $investment->ends_on->toDateString() >= $month->toDateString());
    }
}
