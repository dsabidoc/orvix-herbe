<?php

namespace Tests\Unit\Domain;

use App\Domain\Loans\RoundedLoanQuoteCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RoundedLoanQuoteCalculatorTest extends TestCase
{
    public function test_it_matches_required_example_for_tens_and_hundreds(): void
    {
        $quote = (new RoundedLoanQuoteCalculator)->quote([
            'capital' => '159000',
            'monthly_rate' => '0.020000',
            'collection_fee' => '300',
            'term_months' => 18,
            'first_payment_date' => '2026-08-31',
        ]);

        $this->assertSame(318000, $quote['input']['interest_monthly_cents']);
        $this->assertSame(5724000, $quote['input']['interest_total_cents']);
        $this->assertSame(540000, $quote['input']['collection_total_cents']);
        $this->assertSame(21624000, $quote['input']['total_without_collection_cents']);
        $this->assertSame(22164000, $quote['input']['total_cents']);

        $tens = $quote['options']['tens'];
        $this->assertSame('12370.00', $tens['first_payment']);
        $this->assertSame('12310.00', $tens['regular_payment']);
        $this->assertSame('8890.00', $tens['installments'][0]['principal']);
        $this->assertSame('8830.00', $tens['installments'][1]['principal']);

        $hundreds = $quote['options']['hundreds'];
        $this->assertSame('12540.00', $hundreds['first_payment']);
        $this->assertSame('12300.00', $hundreds['regular_payment']);
        $this->assertSame('9060.00', $hundreds['installments'][0]['principal']);
        $this->assertSame('8820.00', $hundreds['installments'][1]['principal']);

        $this->assertSame($tens['total_cents'], $hundreds['total_cents']);
    }

    public function test_it_keeps_totals_exact_for_long_terms_decimal_rate_and_zero_collection(): void
    {
        $quote = (new RoundedLoanQuoteCalculator)->quote([
            'capital' => '123456.78',
            'monthly_rate' => '0.017500',
            'collection_fee' => '0',
            'term_months' => 70,
            'first_payment_date' => '2026-01-31',
        ]);

        foreach ($quote['options'] as $option) {
            $this->assertSame($quote['input']['capital_cents'], array_sum(array_column($option['installments'], 'principal_cents')));
            $this->assertSame($quote['input']['interest_total_cents'], array_sum(array_column($option['installments'], 'interest_cents')));
            $this->assertSame(0, array_sum(array_column($option['installments'], 'administration_fee_cents')));
            $this->assertSame($quote['input']['total_cents'], array_sum(array_column($option['installments'], 'amount_cents')));
            $this->assertSame(0, $option['installments'][69]['balance_cents']);
            $this->assertSame('2026-02-28', $option['installments'][1]['due_date']);
        }
    }

    public function test_it_rejects_negative_values_and_payment_that_does_not_cover_charges(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RoundedLoanQuoteCalculator)->quote([
            'capital' => '1000',
            'monthly_rate' => '2.000000',
            'collection_fee' => '500',
            'term_months' => 70,
            'first_payment_date' => '2026-08-10',
        ]);
    }
}
