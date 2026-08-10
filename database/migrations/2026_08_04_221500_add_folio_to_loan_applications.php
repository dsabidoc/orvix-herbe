<?php

use App\Models\LoanApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->string('folio')->nullable()->unique()->after('public_id');
        });

        LoanApplication::query()
            ->whereNull('folio')
            ->orderBy('id')
            ->each(fn (LoanApplication $application) => $application->forceFill([
                'folio' => sprintf('SOL-%s-%04d', $application->created_at?->format('y') ?? now('America/Merida')->format('y'), $application->id),
            ])->save());
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropUnique(['folio']);
            $table->dropColumn('folio');
        });
    }
};
