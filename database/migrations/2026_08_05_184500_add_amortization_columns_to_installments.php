<?php

use App\Domain\Loans\LoanScheduleCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->decimal('principal_amount', 15, 2)->default(0)->after('contract_amount');
            $table->decimal('interest_amount', 15, 2)->default(0)->after('principal_amount');
            $table->decimal('interest_vat_amount', 15, 2)->default(0)->after('interest_amount');
            $table->decimal('capital_balance', 15, 2)->default(0)->after('interest_vat_amount');
        });

        DB::table('loans')
            ->orderBy('id')
            ->each(function ($loan) {
                $monthlyRate = (float) $loan->monthly_rate > 1
                    ? number_format(((float) $loan->monthly_rate) / 100, 6, '.', '')
                    : $loan->monthly_rate;
                $calculator = app(LoanScheduleCalculator::class);
                $schedule = $calculator->calculate([
                    'capital' => $loan->capital,
                    'monthly_rate' => $monthlyRate,
                    'interest_calculation_method' => $loan->interest_calculation_method ?? 'fixed_principal',
                    'term_months' => (int) $loan->term_months,
                    'start_date' => $loan->start_date,
                    'payment_day' => (int) $loan->payment_day,
                ]);

                DB::table('loans')->where('id', $loan->id)->update([
                    'monthly_rate' => $monthlyRate,
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
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount', 'interest_vat_amount', 'capital_balance']);
        });
    }
};
