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
        Schema::create('cash_test_operators', function (Blueprint $table): void {
            $table->foreignId('operator_id')->primary()->constrained()->restrictOnDelete();
        });
        Schema::create('cash_test_loans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('operator_id')->constrained('cash_test_operators', 'operator_id')->restrictOnDelete();
            $table->string('folio')->unique();
            $table->string('client_name');
            $table->string('calculation_method')->default('regular');
            $table->boolean('active')->default(true);
            $table->json('terms');
            $table->timestamps();
        });
        Schema::create('cash_test_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_test_loan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('due_date');
            $table->bigInteger('principal_cents');
            $table->bigInteger('interest_cents');
            $table->timestamps();
            $table->unique(['cash_test_loan_id', 'number']);
        });
        Schema::create('cash_collection_cuts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('operator_id')->constrained('cash_test_operators', 'operator_id')->restrictOnDelete();
            $table->date('cut_on');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['operator_id', 'cut_on']);
        });
        Schema::create('cash_cut_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_test_installment_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->bigInteger('amount_cents');
            $table->boolean('closes_installment')->default(false);
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['cash_test_installment_id', 'removed_at']);
        });
        Schema::create('cash_pending_loans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->string('client_name');
            $table->string('phone')->nullable();
            $table->bigInteger('capital_cents');
            $table->string('status')->default('draft');
            $table->string('planned_funding');
            $table->json('terms');
            $table->json('schedule');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cash_cut_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('cash_collection_cut_id')->constrained()->restrictOnDelete();
            $table->char('fingerprint', 64);
            $table->timestamps();
        });
        foreach (['cajas.cuts.prepare', 'cajas.payments.report', 'cajas.advances.report', 'cajas.advances.include-interest', 'cajas.pending-loans.create', 'cajas.pending-loans.view', 'cajas.pending-loans.authorize'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['cash_cut_commands', 'cash_pending_loans', 'cash_cut_items', 'cash_collection_cuts', 'cash_test_installments', 'cash_test_loans', 'cash_test_operators'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
