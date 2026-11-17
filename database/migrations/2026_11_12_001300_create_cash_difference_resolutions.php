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
        Schema::create('cash_difference_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_reconciliation_id')->nullable()->unique()->constrained('cash_reconciliations')->restrictOnDelete();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->text('evidence');
            $table->json('input');
            $table->json('proposal');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cash_difference_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_difference_resolution_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('cash_difference_commands', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->primary();
            $table->string('fingerprint', 64);
            $table->foreignId('cash_difference_resolution_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        foreach (['cajas.differences.request', 'cajas.differences.resolve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_difference_resolutions')->exists()) {
            throw new RuntimeException('No se puede eliminar el historial de aclaraciones.');
        }
        Schema::dropIfExists('cash_difference_commands');
        Schema::dropIfExists('cash_difference_claims');
        Schema::dropIfExists('cash_difference_resolutions');
    }
};
