<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_test_loans', function (Blueprint $table): void {
            $table->json('schedule')->nullable();
            $table->boolean('collectible')->default(true);
        });
        Schema::create('cash_loan_conversions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_pending_loan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_test_loan_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_cut_offset_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_cut_review_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('cut_version');
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Permission::findOrCreate('cajas.pending-loans.convert-test', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_loan_conversions');
        Schema::table('cash_test_loans', fn (Blueprint $table) => $table->dropColumn(['schedule', 'collectible']));
    }
};
