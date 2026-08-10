<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now('America/Merida');
        $primaryInvestor = DB::table('investors')->where('name', 'Herbe Rodriguez')->first();

        if (! $primaryInvestor) {
            $primaryInvestorId = DB::table('investors')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'name' => 'Herbe Rodriguez',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $primaryInvestorId = $primaryInvestor->id;
        }

        DB::table('loans')
            ->orderBy('id')
            ->each(function ($loan) use ($primaryInvestorId, $now) {
                $hasInvestments = DB::table('investments')->where('loan_id', $loan->id)->exists();

                if ($hasInvestments) {
                    return;
                }

                DB::table('investments')->insert([
                    'public_id' => (string) Str::ulid(),
                    'investor_id' => $primaryInvestorId,
                    'loan_id' => $loan->id,
                    'vehicle_id' => $loan->vehicle_id,
                    'amount' => $loan->capital,
                    'investor_share_rate' => '1.000000',
                    'administrator_share_rate' => '0.000000',
                    'starts_on' => $loan->start_date,
                    'status' => 'active',
                    'agreement_snapshot' => json_encode([
                        'role' => 'principal',
                        'capital_percent' => 100,
                        'interest_share_percent' => 100,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        //
    }
};
