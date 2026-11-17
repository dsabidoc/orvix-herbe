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
        Schema::table('cash_account_user', function (Blueprint $table): void {
            $table->boolean('can_count')->default(false);
            $table->boolean('can_close')->default(false);
        });
        Schema::create('cash_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_account_id')->nullable()->unique()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('previous_close_id')->nullable()->constrained('cash_reconciliations')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('source_token', 64);
            $table->string('status')->default('pending');
            $table->bigInteger('expected_cents');
            $table->bigInteger('counted_cents');
            $table->bigInteger('difference_cents');
            $table->json('snapshot');
            $table->text('evidence');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->uuid('resolution_key')->nullable()->unique();
            $table->string('resolution_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['cash_account_id', 'status', 'id']);
        });
        foreach (['cajas.reconciliations.record', 'cajas.reconciliations.close'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_reconciliations')->exists()) {
            throw new RuntimeException('No se puede eliminar el historial de arqueos y cierres.');
        }
        Schema::dropIfExists('cash_reconciliations');
        Schema::table('cash_account_user', fn (Blueprint $table) => $table->dropColumn(['can_count', 'can_close']));
    }
};
