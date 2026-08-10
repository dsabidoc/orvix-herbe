<?php

namespace App\Domain\Loans;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class LoanScheduleCalculator
{
    /**
     * @param array{
     *     capital:string|int,
     *     monthly_rate:string|int,
     *     administration_fee?:string|int,
     *     vat_enabled?:bool|int|string,
     *     term_months:int,
     *     start_date:string,
     *     payment_day:int,
     *     interest_calculation_method?:'fixed_principal'|'outstanding_balance',
     *     rounding_increment?:int,
     *     rounding_adjustment?:'first'|'second'|'last',
     *     first_due_rule?:'next_payment_day'|'following_month'
     * } $input
     */
    public function calculate(array $input): LoanSchedule
    {
        $capital = BigDecimal::of((string) $input['capital']);
        $monthlyRate = BigDecimal::of((string) $input['monthly_rate']);
        $administrationFee = BigDecimal::of((string) ($input['administration_fee'] ?? 0));
        $termMonths = (int) $input['term_months'];
        $paymentDay = (int) $input['payment_day'];
        $roundingIncrement = (int) ($input['rounding_increment'] ?? 10);
        $roundingAdjustment = $input['rounding_adjustment'] ?? 'first';
        $firstDueRule = $input['first_due_rule'] ?? 'next_payment_day';
        $interestCalculationMethod = $input['interest_calculation_method'] ?? 'fixed_principal';
        $vatEnabled = filter_var($input['vat_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $vatRate = $vatEnabled ? 0.16 : 0.0;

        if ($capital->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('El capital debe ser mayor a cero.');
        }

        if ($monthlyRate->isLessThan(0)) {
            throw new InvalidArgumentException('La tasa mensual no puede ser negativa.');
        }

        if ($administrationFee->isLessThan(0)) {
            throw new InvalidArgumentException('Los gastos de administracion no pueden ser negativos.');
        }

        if ($termMonths < 1) {
            throw new InvalidArgumentException('El plazo debe ser de al menos un mes.');
        }

        if ($paymentDay < 1 || $paymentDay > 31) {
            throw new InvalidArgumentException('El dia de pago debe estar entre 1 y 31.');
        }

        if ($roundingIncrement < 1) {
            throw new InvalidArgumentException('El incremento de redondeo debe ser positivo.');
        }

        if (! in_array($roundingAdjustment, ['first', 'second', 'last'], true)) {
            throw new InvalidArgumentException('El ajuste de redondeo debe aplicarse a la primera, segunda o ultima letra.');
        }

        $capitalCents = $this->decimalToCents($capital);
        $administrationFeeCents = $this->decimalToCents($administrationFee);

        if ($interestCalculationMethod === 'outstanding_balance') {
            return $this->calculateOutstandingBalance(
                capitalCents: $capitalCents,
                monthlyRate: $monthlyRate->toFloat(),
                administrationFeeCents: $administrationFeeCents,
                vatRate: $vatRate,
                termMonths: $termMonths,
                paymentDay: $paymentDay,
                startDate: CarbonImmutable::parse($input['start_date']),
                firstDueRule: $firstDueRule,
                incrementCents: $roundingIncrement * 100,
            );
        }

        if ($interestCalculationMethod !== 'fixed_principal') {
            throw new InvalidArgumentException('El tipo de calculo de interes no es valido.');
        }

        $interest = $capital->multipliedBy($monthlyRate)->multipliedBy($termMonths);
        $administrationFeeTotal = $administrationFee->multipliedBy($termMonths);
        $interestVat = $interest->plus($administrationFeeTotal)->multipliedBy((string) $vatRate);
        $contractTotal = $capital
            ->plus($interest)
            ->plus($interestVat)
            ->plus($administrationFeeTotal)
            ->toScale(2, RoundingMode::HalfUp);
        $contractTotalCents = $this->decimalToCents($contractTotal);
        $interestCents = $contractTotalCents - $capitalCents;
        $monthlyInterestCents = $this->decimalToCents($capital->multipliedBy($monthlyRate));
        $monthlyVatCents = (int) round(($monthlyInterestCents + $administrationFeeCents) * $vatRate);

        $incrementCents = $roundingIncrement * 100;
        $averageCents = intdiv($contractTotalCents + intdiv($termMonths, 2), $termMonths);
        $baseInstallmentCents = $administrationFeeCents > 0
            ? $this->roundUpToIncrement($averageCents, $incrementCents)
            : $this->roundDownToIncrement($averageCents, $incrementCents);

        $installments = [];
        $baseTotal = $baseInstallmentCents * ($termMonths - 1);
        $adjustedInstallmentCents = $contractTotalCents - $baseTotal;
        $adjustedNumber = match ($roundingAdjustment) {
            'first' => 1,
            'second' => min(2, $termMonths),
            default => $termMonths,
        };
        $dueDate = $this->firstDueDate(CarbonImmutable::parse($input['start_date']), $paymentDay, $firstDueRule);

        for ($number = 1; $number <= $termMonths; $number++) {
            $amountCents = $number === $adjustedNumber ? $adjustedInstallmentCents : $baseInstallmentCents;
            $principalCents = max(0, $amountCents - $monthlyInterestCents - $monthlyVatCents - $administrationFeeCents);
            $capitalBalanceCents = max(0, $capitalCents - array_sum(array_column($installments, 'principal_cents')) - $principalCents);

            $installments[] = [
                'number' => $number,
                'due_date' => $dueDate->toDateString(),
                'amount_cents' => $amountCents,
                'amount' => LoanSchedule::formatCents($amountCents),
                'principal_cents' => $principalCents,
                'principal' => LoanSchedule::formatCents($principalCents),
                'administration_fee_cents' => $administrationFeeCents,
                'administration_fee' => LoanSchedule::formatCents($administrationFeeCents),
                'interest_cents' => $monthlyInterestCents,
                'interest' => LoanSchedule::formatCents($monthlyInterestCents),
                'interest_vat_cents' => $monthlyVatCents,
                'interest_vat' => LoanSchedule::formatCents($monthlyVatCents),
                'balance_cents' => $capitalBalanceCents,
                'balance' => LoanSchedule::formatCents($capitalBalanceCents),
            ];

            $dueDate = $this->nextDueDate($dueDate, $paymentDay);
        }

        return new LoanSchedule(
            capitalCents: $capitalCents,
            interestCents: $interestCents,
            contractTotalCents: $contractTotalCents,
            baseInstallmentCents: $baseInstallmentCents,
            installments: $installments,
        );
    }

    private function calculateOutstandingBalance(
        int $capitalCents,
        float $monthlyRate,
        int $administrationFeeCents,
        float $vatRate,
        int $termMonths,
        int $paymentDay,
        CarbonImmutable $startDate,
        string $firstDueRule,
        int $incrementCents,
    ): LoanSchedule {
        $effectiveRate = $monthlyRate * (1 + $vatRate);
        $basePaymentCents = $effectiveRate == 0.0
            ? intdiv($capitalCents + intdiv($termMonths, 2), $termMonths)
            : (int) round(($capitalCents * $effectiveRate) / (1 - ((1 + $effectiveRate) ** (-$termMonths))));
        $basePaymentCents = $this->roundDownToIncrement($basePaymentCents, $incrementCents);
        $administrationFeeVatCents = (int) round($administrationFeeCents * $vatRate);
        $dueDate = $this->firstDueDate($startDate, $paymentDay, $firstDueRule);
        $remainingCents = $capitalCents;
        $interestCents = 0;
        $installments = [];

        for ($number = 1; $number <= $termMonths; $number++) {
            $periodInterestCents = (int) round($remainingCents * $monthlyRate);
            $periodVatCents = (int) round(($periodInterestCents + $administrationFeeCents) * $vatRate);
            $financingPaymentCents = $number === $termMonths
                ? $remainingCents + $periodInterestCents + $periodVatCents
                : $basePaymentCents;
            $amountCents = $number === $termMonths
                ? $financingPaymentCents + $administrationFeeCents
                : $this->roundUpToIncrement($financingPaymentCents + $administrationFeeCents + $administrationFeeVatCents, $incrementCents);
            $principalCents = max(0, $amountCents - $periodInterestCents - $periodVatCents);
            $principalCents = max(0, $principalCents - $administrationFeeCents);
            $remainingCents = max(0, $remainingCents - $principalCents);
            $interestCents += $periodInterestCents + $periodVatCents;

            $installments[] = [
                'number' => $number,
                'due_date' => $dueDate->toDateString(),
                'amount_cents' => $amountCents,
                'amount' => LoanSchedule::formatCents($amountCents),
                'administration_fee_cents' => $administrationFeeCents,
                'administration_fee' => LoanSchedule::formatCents($administrationFeeCents),
                'interest_cents' => $periodInterestCents,
                'interest' => LoanSchedule::formatCents($periodInterestCents),
                'interest_vat_cents' => $periodVatCents,
                'interest_vat' => LoanSchedule::formatCents($periodVatCents),
                'principal_cents' => $principalCents,
                'principal' => LoanSchedule::formatCents($principalCents),
                'balance_cents' => $remainingCents,
                'balance' => LoanSchedule::formatCents($remainingCents),
            ];

            $dueDate = $this->nextDueDate($dueDate, $paymentDay);
        }

        return new LoanSchedule(
            capitalCents: $capitalCents,
            interestCents: $interestCents,
            contractTotalCents: array_sum(array_column($installments, 'amount_cents')),
            baseInstallmentCents: $basePaymentCents,
            installments: $installments,
        );
    }

    private function decimalToCents(BigDecimal $amount): int
    {
        return $amount
            ->toScale(2, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
    }

    private function roundToNearestIncrement(int $amountCents, int $incrementCents): int
    {
        return intdiv($amountCents + intdiv($incrementCents, 2), $incrementCents) * $incrementCents;
    }

    private function roundDownToIncrement(int $amountCents, int $incrementCents): int
    {
        return intdiv($amountCents, $incrementCents) * $incrementCents;
    }

    private function roundUpToIncrement(int $amountCents, int $incrementCents): int
    {
        return (int) (ceil($amountCents / $incrementCents) * $incrementCents);
    }

    private function firstDueDate(CarbonImmutable $startDate, int $paymentDay, string $rule): CarbonImmutable
    {
        $candidate = $this->dateInMonth($startDate, $paymentDay);

        if ($rule === 'following_month' || $candidate->lessThan($startDate)) {
            return $this->nextDueDate($candidate, $paymentDay);
        }

        return $candidate;
    }

    private function nextDueDate(CarbonImmutable $currentDueDate, int $paymentDay): CarbonImmutable
    {
        return $this->dateInMonth($currentDueDate->addMonthNoOverflow(), $paymentDay);
    }

    private function dateInMonth(CarbonImmutable $date, int $paymentDay): CarbonImmutable
    {
        $lastDay = $date->endOfMonth()->day;

        return $date->day(min($paymentDay, $lastDay))->startOfDay();
    }
}
