<?php

namespace App\Domain\Loans;

use App\Models\Installment;
use App\Support\Money;
use Carbon\CarbonImmutable;

class DelinquencyCalculator
{
    public function forInstallment(Installment $installment, CarbonImmutable|string $operatedOn): int
    {
        $rate = (float) ($installment->loan->delinquency_rate ?? 0);

        if ($rate <= 0) {
            return 0;
        }

        $graceLimit = CarbonImmutable::parse($installment->due_date, 'America/Merida')
            ->addDays((int) ($installment->loan->delinquency_grace_days ?? 0))
            ->toDateString();
        $operatedDate = CarbonImmutable::parse($operatedOn, 'America/Merida')->toDateString();

        if ($graceLimit >= $operatedDate) {
            return 0;
        }

        return (int) round(Money::cents($installment->contract_amount) * ($rate / 100));
    }
}
