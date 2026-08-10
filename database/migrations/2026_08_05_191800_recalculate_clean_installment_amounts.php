<?php

use App\Domain\Loans\LoanScheduleCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $calculator = app(LoanScheduleCalculator::class);

        DB::table('loans')
            ->orderBy('id')
            ->each(function ($loan) use ($calculator) {
                $schedule = $calculator->calculate([
                    'capital' => $loan->capital,
                    'monthly_rate' => $loan->monthly_rate,
                    'interest_calculation_method' => $loan->interest_calculation_method ?? 'fixed_principal',
                    'term_months' => (int) $loan->term_months,
                    'start_date' => $loan->start_date,
                    'payment_day' => (int) $loan->payment_day,
                    'rounding_increment' => 10,
                    'rounding_adjustment' => 'first',
                ]);

                DB::table('loans')->where('id', $loan->id)->update([
                    'contract_total' => $schedule->contractTotal(),
                ]);

                foreach ($schedule->installments as $installment) {
                    $current = DB::table('installments')
                        ->where('loan_id', $loan->id)
                        ->where('number', $installment['number'])
                        ->first();

                    $appliedCents = (int) round(((float) ($current->applied_amount ?? 0)) * 100);
                    $amountCents = (int) $installment['amount_cents'];
                    $remainingAmount = number_format(max(0, $amountCents - $appliedCents) / 100, 2, '.', '');

                    DB::table('installments')
                        ->where('loan_id', $loan->id)
                        ->where('number', $installment['number'])
                        ->update([
                            'contract_amount' => $installment['amount'],
                            'principal_amount' => $installment['principal'] ?? '0.00',
                            'interest_amount' => $installment['interest'] ?? '0.00',
                            'interest_vat_amount' => $installment['interest_vat'] ?? '0.00',
                            'capital_balance' => $installment['balance'] ?? '0.00',
                            'remaining_amount' => $remainingAmount,
                        ]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
