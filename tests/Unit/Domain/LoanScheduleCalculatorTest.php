<?php

namespace Tests\Unit\Domain;

use App\Domain\Loans\LoanScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LoanScheduleCalculatorTest extends TestCase
{
    public function test_it_places_the_rounding_difference_in_the_first_installment_by_default(): void
    {
        $schedule = (new LoanScheduleCalculator)->calculate([
            'capital' => '143000',
            'monthly_rate' => '0.02',
            'term_months' => 36,
            'start_date' => '2026-08-01',
            'payment_day' => 15,
            'rounding_increment' => 10,
        ]);

        $this->assertSame('262433.60', $schedule->contractTotal());
        $this->assertSame('119433.60', $schedule->interest());
        $this->assertSame('7280.00', $schedule->baseInstallment());
        $this->assertSame('7633.60', $schedule->installments[0]['amount']);
        $this->assertSame('7280.00', $schedule->installments[1]['amount']);
        $this->assertSame('7280.00', $schedule->installments[35]['amount']);
        $this->assertSame('2860.00', $schedule->installments[0]['interest']);
        $this->assertSame('457.60', $schedule->installments[0]['interest_vat']);
        $this->assertSame(26243360, array_sum(array_column($schedule->installments, 'amount_cents')));
    }

    public function test_it_can_place_rounding_difference_in_the_second_installment(): void
    {
        $schedule = (new LoanScheduleCalculator)->calculate([
            'capital' => '143000',
            'monthly_rate' => '0.02',
            'term_months' => 36,
            'start_date' => '2026-08-01',
            'payment_day' => 15,
            'rounding_increment' => 10,
            'rounding_adjustment' => 'second',
        ]);

        $this->assertSame('7280.00', $schedule->installments[0]['amount']);
        $this->assertSame('7633.60', $schedule->installments[1]['amount']);
        $this->assertSame(26243360, array_sum(array_column($schedule->installments, 'amount_cents')));
    }

    public function test_it_can_calculate_interest_over_outstanding_balance(): void
    {
        $schedule = (new LoanScheduleCalculator)->calculate([
            'capital' => '100000',
            'monthly_rate' => '0.02',
            'interest_calculation_method' => 'outstanding_balance',
            'term_months' => 36,
            'start_date' => '2026-08-01',
            'payment_day' => 15,
        ]);

        $this->assertLessThan(9600000, $schedule->interestCents);
        $this->assertSame(36, count($schedule->installments));
        $this->assertSame(0, $schedule->installments[35]['balance_cents']);
        $this->assertSame('4120.00', $schedule->installments[0]['amount']);
        $this->assertSame('1800.00', $schedule->installments[0]['principal']);
        $this->assertSame('2000.00', $schedule->installments[0]['interest']);
        $this->assertSame('320.00', $schedule->installments[0]['interest_vat']);
        $this->assertGreaterThan($schedule->installments[1]['interest_cents'], $schedule->installments[0]['interest_cents']);
    }

    public function test_it_keeps_regular_outstanding_balance_payments_clean_and_adjusts_the_last_one(): void
    {
        $schedule = (new LoanScheduleCalculator)->calculate([
            'capital' => '20000',
            'monthly_rate' => '0.02',
            'interest_calculation_method' => 'outstanding_balance',
            'term_months' => 27,
            'start_date' => '2026-02-05',
            'payment_day' => 5,
        ]);

        $regularPayments = array_slice($schedule->installments, 0, 26);

        $this->assertSame('1000.00', $schedule->baseInstallment());
        $this->assertSame([], array_values(array_filter(
            $regularPayments,
            fn (array $installment) => $installment['amount_cents'] % 1000 !== 0
        )));
        $this->assertGreaterThan(100000, $schedule->installments[26]['amount_cents']);
        $this->assertSame(0, $schedule->installments[26]['balance_cents']);
    }

    public function test_it_rejects_invalid_capital(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LoanScheduleCalculator)->calculate([
            'capital' => '0',
            'monthly_rate' => '0.02',
            'term_months' => 36,
            'start_date' => '2026-08-01',
            'payment_day' => 15,
        ]);
    }
}
