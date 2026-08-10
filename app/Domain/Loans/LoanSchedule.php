<?php

namespace App\Domain\Loans;

final readonly class LoanSchedule
{
    /**
     * @param  list<array{number:int,due_date:string,amount_cents:int,amount:string}>  $installments
     */
    public function __construct(
        public int $capitalCents,
        public int $interestCents,
        public int $contractTotalCents,
        public int $baseInstallmentCents,
        public array $installments,
    ) {}

    public function capital(): string
    {
        return self::formatCents($this->capitalCents);
    }

    public function interest(): string
    {
        return self::formatCents($this->interestCents);
    }

    public function contractTotal(): string
    {
        return self::formatCents($this->contractTotalCents);
    }

    public function baseInstallment(): string
    {
        return self::formatCents($this->baseInstallmentCents);
    }

    public static function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
