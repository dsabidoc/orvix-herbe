<?php

namespace App\Domain\Loans;

use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class RoundedLoanQuoteCalculator
{
    /**
     * @param  array{capital:string|int,monthly_rate:string|int,collection_fee?:string|int,term_months:int,first_payment_date:string}  $input
     * @return array{input:array<string, mixed>, options:array<string, array<string, mixed>>}
     */
    public function quote(array $input): array
    {
        $capitalCents = $this->decimalToCents(BigDecimal::of((string) $input['capital']));
        $monthlyRate = BigDecimal::of((string) $input['monthly_rate']);
        $collectionFeeCents = $this->decimalToCents(BigDecimal::of((string) ($input['collection_fee'] ?? 0)));
        $termMonths = (int) $input['term_months'];
        $firstPaymentDate = CarbonImmutable::parse($input['first_payment_date']);

        if ($capitalCents <= 0) {
            throw new InvalidArgumentException('El capital debe ser mayor a cero.');
        }

        if ($monthlyRate->isLessThan(0)) {
            throw new InvalidArgumentException('La tasa mensual no puede ser negativa.');
        }

        if ($collectionFeeCents < 0) {
            throw new InvalidArgumentException('La cobranza por pago no puede ser negativa.');
        }

        if ($termMonths < 1) {
            throw new InvalidArgumentException('El plazo debe ser de al menos un mes.');
        }

        $interestMonthlyCents = $this->decimalToCents(
            BigDecimal::of($capitalCents)
                ->dividedBy(100, 8, RoundingMode::HalfUp)
                ->multipliedBy($monthlyRate)
        );
        $interestTotalCents = $interestMonthlyCents * $termMonths;
        $collectionTotalCents = $collectionFeeCents * $termMonths;
        $totalWithoutCollectionCents = $capitalCents + $interestTotalCents;
        $totalCents = $totalWithoutCollectionCents + $collectionTotalCents;

        return [
            'input' => [
                'capital_cents' => $capitalCents,
                'monthly_rate' => (string) $monthlyRate,
                'term_months' => $termMonths,
                'collection_fee_cents' => $collectionFeeCents,
                'interest_monthly_cents' => $interestMonthlyCents,
                'interest_total_cents' => $interestTotalCents,
                'collection_total_cents' => $collectionTotalCents,
                'total_without_collection_cents' => $totalWithoutCollectionCents,
                'total_cents' => $totalCents,
                'first_payment_date' => $firstPaymentDate->toDateString(),
            ],
            'options' => [
                'tens' => $this->option($capitalCents, $interestMonthlyCents, $collectionFeeCents, $termMonths, $totalCents, $firstPaymentDate, 10),
                'hundreds' => $this->option($capitalCents, $interestMonthlyCents, $collectionFeeCents, $termMonths, $totalCents, $firstPaymentDate, 100),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(
        int $capitalCents,
        int $interestMonthlyCents,
        int $collectionFeeCents,
        int $termMonths,
        int $totalCents,
        CarbonImmutable $firstPaymentDate,
        int $roundingMultiple,
    ): array {
        $basePaymentCents = intdiv($totalCents + intdiv($termMonths, 2), $termMonths);
        $regularPaymentCents = intdiv($basePaymentCents, $roundingMultiple * 100) * ($roundingMultiple * 100);

        if ($regularPaymentCents <= $interestMonthlyCents + $collectionFeeCents) {
            throw new InvalidArgumentException('El pago regular redondeado no cubre interes y cobranza. Sube el plazo, baja la tasa o usa otro monto.');
        }

        $firstPaymentCents = $totalCents - ($regularPaymentCents * ($termMonths - 1));
        $remainingCapitalCents = $capitalCents;
        $installments = [];

        for ($number = 1; $number <= $termMonths; $number++) {
            $amountCents = $number === 1 ? $firstPaymentCents : $regularPaymentCents;
            $principalCents = $amountCents - $interestMonthlyCents - $collectionFeeCents;

            if ($number === $termMonths) {
                $principalCents = $remainingCapitalCents;
                $amountCents = $principalCents + $interestMonthlyCents + $collectionFeeCents;
            }

            $previousBalanceCents = $remainingCapitalCents;
            $remainingCapitalCents -= $principalCents;

            $installments[] = [
                'number' => $number,
                'due_date' => $this->dueDate($firstPaymentDate, $number)->toDateString(),
                'amount_cents' => $amountCents,
                'amount' => Money::decimal($amountCents),
                'principal_cents' => $principalCents,
                'principal' => Money::decimal($principalCents),
                'administration_fee_cents' => $collectionFeeCents,
                'administration_fee' => Money::decimal($collectionFeeCents),
                'interest_cents' => $interestMonthlyCents,
                'interest' => Money::decimal($interestMonthlyCents),
                'interest_vat_cents' => 0,
                'interest_vat' => '0.00',
                'previous_balance_cents' => $previousBalanceCents,
                'previous_balance' => Money::decimal($previousBalanceCents),
                'balance_cents' => $remainingCapitalCents,
                'balance' => Money::decimal($remainingCapitalCents),
            ];
        }

        $this->assertConsistent($installments, $capitalCents, $interestMonthlyCents * $termMonths, $collectionFeeCents * $termMonths, $totalCents);

        return [
            'key' => $roundingMultiple === 10 ? 'tens' : 'hundreds',
            'name' => $roundingMultiple === 10 ? 'Opcion 1' : 'Opcion 2',
            'description' => $roundingMultiple === 10 ? 'Pagos redondeados a decenas' : 'Pagos redondeados a centenas',
            'rounding_multiple' => $roundingMultiple,
            'first_payment_cents' => $firstPaymentCents,
            'first_payment' => Money::decimal($firstPaymentCents),
            'regular_payment_cents' => $regularPaymentCents,
            'regular_payment' => Money::decimal($regularPaymentCents),
            'remaining_payments' => $termMonths - 1,
            'total_payments' => $termMonths,
            'total_cents' => $totalCents,
            'total' => Money::decimal($totalCents),
            'installments' => $installments,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $installments
     */
    private function assertConsistent(array $installments, int $capitalCents, int $interestTotalCents, int $collectionTotalCents, int $totalCents): void
    {
        if (array_sum(array_column($installments, 'principal_cents')) !== $capitalCents
            || array_sum(array_column($installments, 'interest_cents')) !== $interestTotalCents
            || array_sum(array_column($installments, 'administration_fee_cents')) !== $collectionTotalCents
            || array_sum(array_column($installments, 'amount_cents')) !== $totalCents
            || end($installments)['balance_cents'] !== 0) {
            throw new InvalidArgumentException('El calendario calculado no cuadra con los totales del credito.');
        }
    }

    private function dueDate(CarbonImmutable $firstPaymentDate, int $number): CarbonImmutable
    {
        $target = $firstPaymentDate->addMonthsNoOverflow($number - 1);
        $day = min($firstPaymentDate->day, $target->endOfMonth()->day);

        return $target->setDay($day);
    }

    private function decimalToCents(BigDecimal $amount): int
    {
        return $amount
            ->toScale(2, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
    }
}
