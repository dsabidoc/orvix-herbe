<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_pending_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_pending_loan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('delivered_on');
            $table->string('recipient_name', 150);
            $table->string('reference', 500);
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('cash_loan_conversions', function (Blueprint $table): void {
            $table->unsignedBigInteger('cash_cut_offset_id')->nullable()->change();
            $table->foreignId('cash_pending_disbursement_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Permission::findOrCreate('cajas.pending-loans.disburse-test', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_pending_disbursements')->exists()) {
            throw new RuntimeException('No se puede retirar el historial de entregas de capital confirmadas.');
        }
        Schema::table('cash_loan_conversions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_pending_disbursement_id');
            $table->unsignedBigInteger('cash_cut_offset_id')->nullable(false)->change();
        });
        Schema::dropIfExists('cash_pending_disbursements');
    }
};
