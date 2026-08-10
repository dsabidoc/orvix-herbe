<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanSchedule;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Domain\Loans\RoundedLoanQuoteCalculator;
use App\Models\Operator;
use App\Models\Simulation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SimulatorController extends Controller
{
    public function index(Request $request, LoanScheduleCalculator $calculator, RoundedLoanQuoteCalculator $roundedCalculator): View
    {
        $schedule = null;
        $simulation = null;
        $displaySchedule = null;
        $roundedQuote = null;
        $openingFeeCents = 0;
        $contractTotalWithFee = null;
        $input = [
            'client_name' => $request->input('client_name', ''),
            'calculation_method' => $request->input('calculation_method', 'regular'),
            'operator_id' => $request->input('operator_id', ''),
            'capital' => $request->input('capital', '50000'),
            'rate_type' => $request->input('rate_type', 'monthly'),
            'rate_value' => $request->input('rate_value', '2'),
            'administration_fee' => $request->input('administration_fee', '0'),
            'vat_enabled' => $request->input('vat_enabled', '1'),
            'interest_calculation_method' => $request->input('interest_calculation_method', 'fixed_principal'),
            'term_months' => $request->input('term_months', 10),
            'start_date' => $request->input('start_date', now('America/Merida')->toDateString()),
            'first_payment_date' => $request->input('first_payment_date', now('America/Merida')->toDateString()),
            'payment_day' => $request->input('payment_day', now('America/Merida')->day),
            'opening_fee_type' => $request->input('opening_fee_type', 'none'),
            'opening_fee_value' => $request->input('opening_fee_value', '0'),
        ];

        if ($request->filled('capital')) {
            $data = $request->validate([
                'client_name' => ['required', 'string', 'max:180'],
                'calculation_method' => ['required', 'in:regular,rounded'],
                'operator_id' => ['required', 'exists:operators,id'],
                'capital' => ['required', 'numeric', 'min:1'],
                'rate_type' => ['required', 'in:monthly,annual'],
                'rate_value' => ['required', 'numeric', 'min:0'],
                'administration_fee' => ['nullable', 'numeric', 'min:0'],
                'vat_enabled' => ['nullable', 'boolean'],
                'interest_calculation_method' => ['required', 'in:fixed_principal,outstanding_balance'],
                'term_months' => ['required', 'integer', 'min:1'],
                'start_date' => ['required', 'date'],
                'first_payment_date' => ['nullable', 'date'],
                'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
                'opening_fee_type' => ['required', 'in:none,percent,fixed'],
                'opening_fee_value' => ['required', 'numeric', 'min:0'],
            ]);

            $monthlyRate = $this->monthlyRate((float) $data['rate_value'], $data['rate_type']);
            $data['monthly_rate'] = number_format($monthlyRate, 6, '.', '');
            $data['administration_fee_type'] = 'monthly';
            $data['vat_enabled'] = $request->boolean('vat_enabled', true);
            $capturedAdministrationFee = $data['administration_fee'] ?? '0.00';
            $data['administration_fee'] = number_format((float) $capturedAdministrationFee, 2, '.', '');

            if ($data['calculation_method'] === 'rounded') {
                $roundedQuote = $roundedCalculator->quote([
                    'capital' => $data['capital'],
                    'monthly_rate' => $data['monthly_rate'],
                    'collection_fee' => $data['administration_fee'],
                    'term_months' => (int) $data['term_months'],
                    'first_payment_date' => $data['first_payment_date'] ?: $data['start_date'],
                ]);
                $data['administration_fee'] = $capturedAdministrationFee;
                $input = $data;

                return view('simulator.index', [
                    'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
                    'input' => $input,
                    'schedule' => null,
                    'displaySchedule' => null,
                    'roundedQuote' => $roundedQuote,
                    'openingFeeAmount' => '0.00',
                    'contractTotalWithFee' => Money::decimal($roundedQuote['input']['total_cents']),
                    'simulation' => null,
                    'interestCalculationLabel' => 'Capital fijo con redondeo',
                ]);
            }

            $schedule = $calculator->calculate($data);
            $openingFeeCents = $this->openingFeeCents((float) $data['capital'], $data['opening_fee_type'], (float) $data['opening_fee_value']);
            $displaySchedule = $this->scheduleWithOpeningFee($schedule->installments, $openingFeeCents);
            $contractTotalWithFee = Money::decimal($schedule->contractTotalCents + $openingFeeCents);
            $simulation = Simulation::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $request->user()->id,
                'capital' => $schedule->capital(),
                'monthly_rate' => $data['monthly_rate'],
                'administration_fee' => $data['administration_fee'],
                'administration_fee_type' => $data['administration_fee_type'],
                'vat_enabled' => $data['vat_enabled'],
                'rate_type' => $data['rate_type'],
                'interest_calculation_method' => $data['interest_calculation_method'],
                'term_months' => $data['term_months'],
                'start_date' => $data['start_date'],
                'payment_day' => $data['payment_day'],
                'rounding_increment' => 10,
                'rounding_adjustment' => 'first',
                'opening_fee_type' => $data['opening_fee_type'],
                'opening_fee_value' => $data['opening_fee_value'],
                'opening_fee_amount' => Money::decimal($openingFeeCents),
                'total_interest' => LoanSchedule::formatCents($schedule->interestCents),
                'contract_total' => $contractTotalWithFee,
                'schedule' => $displaySchedule,
                'status' => 'draft',
            ]);
            $data['administration_fee'] = $capturedAdministrationFee;
            $input = $data;
        }

        return view('simulator.index', [
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'input' => $input,
            'schedule' => $schedule,
            'displaySchedule' => $displaySchedule,
            'roundedQuote' => $roundedQuote,
            'openingFeeAmount' => Money::decimal($openingFeeCents),
            'contractTotalWithFee' => $contractTotalWithFee,
            'simulation' => $simulation,
            'interestCalculationLabel' => $this->interestCalculationLabel($input['interest_calculation_method']),
        ]);
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    private function openingFeeCents(float $capital, string $type, float $value): int
    {
        return match ($type) {
            'percent' => (int) round($capital * ($value / 100) * 100),
            'fixed' => (int) round($value * 100),
            default => 0,
        };
    }

    private function interestCalculationLabel(string $method): string
    {
        return $method === 'outstanding_balance' ? 'Saldo insoluto' : 'Fijo sobre capital';
    }

    private function scheduleWithOpeningFee(array $installments, int $openingFeeCents): array
    {
        if ($openingFeeCents === 0 || $installments === []) {
            return $installments;
        }

        $installments[0]['amount_cents'] += $openingFeeCents;
        $installments[0]['amount'] = Money::decimal($installments[0]['amount_cents']);

        return $installments;
    }
}
