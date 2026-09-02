<?php

namespace App\Domain\Investors;

use App\Domain\Loans\LoanSettlementService;
use App\Models\CollectionMovement;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InvestorDashboardMetrics
{
    private const RETURN_MOVEMENT_SIGNS = [
        'payment_returns_recorded' => 1,
        'payment_returns_reversed' => -1,
        'returns_recorded' => 1,
    ];

    public function __construct(private readonly LoanSettlementService $settlementService) {}

    /**
     * @param  Collection<int, Loan>  $loans
     * @return array{settle_today_cents:int,expected_period_cents:int,collected_period_cents:int,overdue_cents:int}
     */
    public function calculate(
        Investor $investor,
        Collection $loans,
        CarbonImmutable $today,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): array {
        $loans->load([
            'installments',
            'investments' => fn ($query) => $query
                ->where('investor_id', $investor->id)
                ->where('status', 'active'),
        ]);

        $settleTodayCents = 0;
        $expectedPeriodCents = 0;
        $overdueCents = 0;

        foreach ($loans as $loan) {
            $investment = $loan->investments->first();

            if (! $investment) {
                continue;
            }

            if ($this->investmentCoversDate($investment, $today)) {
                foreach ($this->settlementService->quote($loan, $today)['rows'] as $row) {
                    $settleTodayCents += $this->shareCents(
                        $loan,
                        $investment,
                        (int) $row['principal_cents'],
                        (int) $row['interest_cents'],
                    );
                }
            }

            foreach ($loan->installments as $installment) {
                $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();

                if (! $this->investmentCoversDate($investment, $dueDate)) {
                    continue;
                }

                $principalCents = Money::cents($installment->principal_amount);
                $interestCents = Money::cents($installment->interest_amount);

                if ($dueDate->betweenIncluded($periodStart, $periodEnd)) {
                    $expectedPeriodCents += $this->shareCents($loan, $investment, $principalCents, $interestCents);
                }

                if ($dueDate->lt($periodStart) && Money::cents($installment->remaining_amount) > 0) {
                    $operationalCents = $principalCents + $interestCents;
                    $pendingOperationalCents = min(Money::cents($installment->remaining_amount), $operationalCents);

                    if ($operationalCents > 0 && $pendingOperationalCents > 0) {
                        $pendingRatio = min(1, $pendingOperationalCents / $operationalCents);
                        $overdueCents += $this->shareCents(
                            $loan,
                            $investment,
                            (int) round($principalCents * $pendingRatio),
                            (int) round($interestCents * $pendingRatio),
                        );
                    }
                }
            }
        }

        return [
            'settle_today_cents' => $settleTodayCents,
            'expected_period_cents' => $expectedPeriodCents,
            'collected_period_cents' => $this->collectedPeriodCents($investor, $loans, $periodStart, $periodEnd),
            'overdue_cents' => $overdueCents,
        ];
    }

    private function shareCents(Loan $loan, object $investment, int $principalCents, int $interestCents): int
    {
        $loanCapitalCents = Money::cents($loan->capital);

        if ($loanCapitalCents <= 0) {
            return 0;
        }

        $capitalShareRate = Money::cents($investment->amount) / $loanCapitalCents;
        $interestShareRate = (float) $investment->investor_share_rate;

        return (int) round($principalCents * $capitalShareRate)
            + (int) round($interestCents * $interestShareRate);
    }

    private function investmentCoversDate(object $investment, CarbonImmutable $date): bool
    {
        return (! $investment->starts_on || $investment->starts_on->toDateString() <= $date->toDateString())
            && (! $investment->ends_on || $investment->ends_on->toDateString() >= $date->toDateString());
    }

    /**
     * @param  Collection<int, Loan>  $loans
     */
    private function collectedPeriodCents(
        Investor $investor,
        Collection $loans,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): int {
        if ($loans->isEmpty()) {
            return 0;
        }

        $returnMovements = InvestorCapitalMovement::query()
            ->where('investor_id', $investor->id)
            ->whereIn('loan_id', $loans->modelKeys())
            ->whereIn('type', array_keys(self::RETURN_MOVEMENT_SIGNS))
            ->get();
        $collectionMovementIds = $returnMovements
            ->map(fn (InvestorCapitalMovement $movement) => (int) data_get($movement->metadata, 'collection_movement_id', 0))
            ->filter()
            ->unique()
            ->values();
        $collectionDates = $collectionMovementIds->isEmpty()
            ? collect()
            : CollectionMovement::query()
                ->whereIn('id', $collectionMovementIds)
                ->pluck('operated_on', 'id');
        $collectedCents = 0;

        foreach ($returnMovements as $movement) {
            $collectionMovementId = (int) data_get($movement->metadata, 'collection_movement_id', 0);
            $operatedOn = CarbonImmutable::parse($collectionDates->get($collectionMovementId) ?? $movement->created_at, 'America/Merida')->startOfDay();

            if (! $operatedOn->betweenIncluded($periodStart, $periodEnd)) {
                continue;
            }

            $collectedCents += self::RETURN_MOVEMENT_SIGNS[$movement->type] * Money::cents($movement->amount);
        }

        return $collectedCents;
    }
}
