<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('installments')
            ->orderBy('id')
            ->chunkById(200, function ($installments) {
                foreach ($installments as $installment) {
                    $operationalCents = $this->cents($installment->principal_amount) + $this->cents($installment->interest_amount);
                    $contractCents = $this->cents($installment->contract_amount);

                    if ($operationalCents <= 0) {
                        $operationalCents = $contractCents;
                    }

                    $appliedCents = min($this->cents($installment->applied_amount), $operationalCents);
                    $remainingCents = $this->cents($installment->remaining_amount) <= 0
                        ? 0
                        : max(0, $operationalCents - $appliedCents);

                    DB::table('installments')
                        ->where('id', $installment->id)
                        ->update([
                            'applied_amount' => $this->decimal($remainingCents === 0 ? $operationalCents : $appliedCents),
                            'remaining_amount' => $this->decimal($remainingCents),
                            'updated_at' => now(),
                        ]);
                }
            });

        DB::table('collection_movements')
            ->whereNotNull('target_installment_id')
            ->whereIn('type', ['ordinary', 'partial'])
            ->orderBy('id')
            ->chunkById(200, function ($movements) {
                foreach ($movements as $movement) {
                    $installment = DB::table('installments')->where('id', $movement->target_installment_id)->first();

                    if (! $installment) {
                        continue;
                    }

                    $operationalCents = $this->cents($installment->principal_amount) + $this->cents($installment->interest_amount);

                    if ($operationalCents <= 0) {
                        continue;
                    }

                    $currentCents = $this->cents($movement->contract_amount);
                    $normalizedCents = min($currentCents, $operationalCents);

                    if ($normalizedCents === $currentCents) {
                        continue;
                    }

                    DB::table('collection_movements')
                        ->where('id', $movement->id)
                        ->update([
                            'contract_amount' => $this->decimal($normalizedCents),
                            'updated_at' => now(),
                        ]);

                    DB::table('payment_allocations')
                        ->where('collection_movement_id', $movement->id)
                        ->where('installment_id', $installment->id)
                        ->update([
                            'amount' => $this->decimal($normalizedCents),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible normalization: contract_amount still preserves the original pagare value.
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
};
