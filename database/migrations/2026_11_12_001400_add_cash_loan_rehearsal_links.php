<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_loan_rehearsal', function (Blueprint $table): void {
            $table->string('name')->primary();
        });
        Schema::table('cash_test_loans', function (Blueprint $table): void {
            $table->foreignId('loan_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::table('cash_test_installments', function (Blueprint $table): void {
            $table->foreignId('installment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('source_digest', 64)->nullable();
        });
        Schema::create('cash_loan_payment_links', function (Blueprint $table): void {
            $table->foreignId('cash_cut_item_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('collection_movement_id')->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('cash_loan_payment_links')->exists()
            || DB::table('cash_test_loans')->whereNotNull('loan_id')->exists()) {
            throw new LogicException('La integración conserva referencias financieras. Restaura la copia del ensayo.');
        }
        Schema::dropIfExists('cash_loan_payment_links');
        Schema::table('cash_test_installments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('installment_id');
            $table->dropColumn('source_digest');
        });
        Schema::table('cash_test_loans', fn (Blueprint $table) => $table->dropConstrainedForeignId('loan_id'));
        Schema::dropIfExists('cash_loan_rehearsal');
    }
};
