<?php

namespace App\Domain\Cajas;

use App\Domain\Loans\LoanScheduleCalculator;
use App\Domain\Loans\RoundedLoanQuoteCalculator;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CashPendingQuote
{
    public function calculate(array $input): array
    {
        CajasSandbox::enforce();
        $data = Validator::make($input, [
            'client_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'capital' => ['required', 'string', 'regex:/^[0-9]{1,9}(\.[0-9]{1,2})?$/', 'numeric', 'gt:0'],
            'monthly_rate_percent' => ['required', 'string', 'regex:/^[0-9]{1,3}(\.[0-9]{1,4})?$/', 'numeric', 'between:0,100'],
            'term_months' => ['required', 'integer', 'between:1,120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'first_payment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'calculation_method' => ['required', Rule::in(['regular', 'rounded', 'interest_only'])],
            'selected_option' => ['required', Rule::in(['tens', 'hundreds'])],
            'administration_fee' => ['required', 'string', 'regex:/^[0-9]{1,7}(\.[0-9]{1,2})?$/'],
            'vat_enabled' => ['required', 'boolean'],
            'planned_funding' => ['required', Rule::in(['cash', 'offset'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $data['term_months'] = (int) $data['term_months'];
        $data['vat_enabled'] = (bool) $data['vat_enabled'];
        $data['monthly_rate'] = (string) BigDecimal::of($data['monthly_rate_percent'])->dividedBy(100, 6);
        $data['payment_day'] = (int) substr($data['first_payment_date'], -2);
        $data['frequency'] = 'monthly';
        $data['interest_calculation_method'] = 'fixed_principal';
        try {
            if ($data['calculation_method'] === 'rounded') {
                if ($data['vat_enabled']) {
                    throw new InvalidArgumentException('El cálculo redondeado existente no incluye IVA; desactívalo para esta modalidad.');
                }
                $quote = app(RoundedLoanQuoteCalculator::class)->quote([...$data, 'collection_fee' => $data['administration_fee']]);
                $option = $quote['options'][$data['selected_option']];
                $schedule = ['capital_cents' => Money::cents($data['capital']), 'total_cents' => $option['total_cents'],
                    'base_payment_cents' => $option['regular_payment_cents'], 'installments' => $option['installments']];
            } else {
                $result = app(LoanScheduleCalculator::class)->calculate([...$data, 'rounding_increment' => $data['selected_option'] === 'hundreds' ? 100 : 10]);
                $schedule = ['capital_cents' => $result->capitalCents, 'total_cents' => $result->contractTotalCents,
                    'base_payment_cents' => $result->baseInstallmentCents, 'installments' => $result->installments];
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['capital' => $exception->getMessage()]);
        }
        if (collect($schedule['installments'])->contains(fn ($row) => $row['amount_cents'] <= 0)
            || ($data['calculation_method'] !== 'interest_only' && array_sum(array_column($schedule['installments'], 'principal_cents')) !== $schedule['capital_cents'])) {
            throw ValidationException::withMessages(['capital' => 'El calendario no es consistente con estas condiciones. Revisa monto, tasa, plazo y redondeo.']);
        }
        $schedule['engine'] = $data['calculation_method'] === 'rounded' ? RoundedLoanQuoteCalculator::class : LoanScheduleCalculator::class;

        return ['terms' => $data, 'schedule' => $schedule, 'hash' => hash('sha256', json_encode([$data, $schedule]))];
    }
}
