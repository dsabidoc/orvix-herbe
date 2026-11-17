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
        Schema::create('cash_transfer_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_transfer_id')->nullable()->unique()->constrained('cash_operations')->restrictOnDelete();
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
            $table->foreignId('reversal_operation_id')->nullable()->unique()->constrained('cash_operations')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cash_transfer_commands', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->primary();
            $table->string('fingerprint', 64);
            $table->foreignId('cash_transfer_correction_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        foreach (['cajas.transfers.correct-request', 'cajas.transfers.correct-resolve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_transfer_corrections')->exists()) {
            throw new RuntimeException('No se puede eliminar el historial de correcciones de traspasos.');
        }
        Schema::dropIfExists('cash_transfer_commands');
        Schema::dropIfExists('cash_transfer_corrections');
    }
};
