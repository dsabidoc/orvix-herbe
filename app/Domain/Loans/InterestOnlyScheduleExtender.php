<?php

namespace App\Domain\Loans;

use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class InterestOnlyScheduleExtender
{
    public function ensureCoverageForScope(Request $request, ?CarbonImmutable $through = null): void
    {
        Loan::query()
            ->where('status', 'active')
            ->where('calculation_method', 'interest_only')
            ->when($request->user()?->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
            ->with('installments')
            ->orderBy('id')
            ->chunkById(100, function ($loans) use ($through): void {
                foreach ($loans as $loan) {
                    $this->ensureCoverage($loan, $through);
                }
            });
    }

    public function ensureCoverage(Loan $loan, ?CarbonImmutable $through = null): void
    {
        if (($loan->calculation_method ?? 'regular') !== 'interest_only' || $loan->status !== 'active') {
            return;
        }

        $through ??= CarbonImmutable::now('America/Merida')->addMonths(12)->endOfMonth();
        $loan->loadMissing('installments');

        $lastInstallment = $loan->installments->sortByDesc('number')->first();
        if (! $lastInstallment) {
            return;
        }

        $currentCapitalCents = Money::cents($lastInstallment->capital_balance ?: $loan->capital);
        if ($currentCapitalCents <= 0 || $lastInstallment->due_date->greaterThanOrEqualTo($through)) {
            return;
        }

        $dueDate = $this->nextDueDate(
            CarbonImmutable::parse($lastInstallment->due_date, 'America/Merida'),
            (int) $loan->payment_day
        );
        $number = (int) $lastInstallment->number + 1;
        $interestCents = (int) round(Money::cents($loan->capital) * (float) $loan->monthly_rate);
        $administrationFeeCents = Money::cents($loan->administration_fee ?? 0);
        $vatRate = $loan->vat_enabled ? 0.16 : 0.0;
        $interestVatCents = (int) round(($interestCents + $administrationFeeCents) * $vatRate);
        $contractCents = $interestCents + $interestVatCents + $administrationFeeCents;

        while ($dueDate->lessThanOrEqualTo($through)) {
            $loan->installments()->create([
                'number' => $number,
                'due_date' => $dueDate->toDateString(),
                'contract_amount' => Money::decimal($contractCents),
                'principal_amount' => '0.00',
                'administration_fee_amount' => Money::decimal($administrationFeeCents),
                'interest_amount' => Money::decimal($interestCents),
                'interest_vat_amount' => Money::decimal($interestVatCents),
                'capital_balance' => Money::decimal($currentCapitalCents),
                'applied_amount' => '0.00',
                'remaining_amount' => Money::decimal($interestCents),
                'status' => 'upcoming',
            ]);

            $number++;
            $dueDate = $this->nextDueDate($dueDate, (int) $loan->payment_day);
        }

        $loan->forceFill(['term_months' => max((int) $loan->term_months, $number - 1)])->save();
    }

    private function nextDueDate(CarbonImmutable $currentDueDate, int $paymentDay): CarbonImmutable
    {
        $date = $currentDueDate->addMonthNoOverflow();
        $lastDay = $date->endOfMonth()->day;

        return $date->day(min($paymentDay, $lastDay))->startOfDay();
    }
}
