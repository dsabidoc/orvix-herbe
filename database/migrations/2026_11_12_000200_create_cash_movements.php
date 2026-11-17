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
        Schema::table('cash_accounts', fn (Blueprint $table) => $table->boolean('is_test')->default(false));
        Schema::table('cash_account_user', function (Blueprint $table): void {
            foreach (['income', 'expense', 'send', 'receive'] as $action) {
                $table->boolean('can_'.$action)->default(false);
            }
        });
        Schema::create('cash_transfer_routes', function (Blueprint $table): void {
            $table->foreignId('source_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->primary(['source_id', 'destination_id']);
        });
        Schema::create('cash_operations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->char('fingerprint', 64);
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->foreignId('source_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('operated_on');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('delivered_on')->nullable();
            $table->date('received_on')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['source_id', 'status']);
            $table->index(['destination_id', 'status']);
        });
        Schema::create('cash_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_operation_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('operated_on');
            $table->timestamp('created_at');
            $table->unique(['cash_operation_id', 'cash_account_id']);
        });
        foreach (['cajas.income.record', 'cajas.expenses.record', 'cajas.transfers.send', 'cajas.transfers.receive', 'cajas.transfers.dispute'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // No financial permissions or scopes are granted to existing people/roles.
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_operations');
        Schema::dropIfExists('cash_transfer_routes');
        Schema::table('cash_account_user', fn (Blueprint $table) => $table->dropColumn(['can_income', 'can_expense', 'can_send', 'can_receive']));
        Schema::table('cash_accounts', fn (Blueprint $table) => $table->dropColumn('is_test'));
    }
};
