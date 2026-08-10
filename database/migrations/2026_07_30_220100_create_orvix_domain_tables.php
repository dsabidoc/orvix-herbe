<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('cut_day')->default(6);
            $table->unsignedSmallInteger('tolerance_days')->default(0);
            $table->unsignedSmallInteger('max_overdue_installments')->default(0);
            $table->boolean('allows_shortfalls')->default(false);
            $table->boolean('assumes_collection_risk')->default(false);
            $table->boolean('covers_installment_when_client_misses')->default(false);
            $table->json('alert_rules')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['status', 'cut_day']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->index();
            $table->string('alternate_phone')->nullable();
            $table->string('email')->nullable();
            $table->json('address')->nullable();
            $table->string('curp')->nullable()->index();
            $table->string('rfc')->nullable()->index();
            $table->string('identification_type')->nullable();
            $table->string('identification_last4', 4)->nullable();
            $table->unsignedSmallInteger('manual_score')->nullable();
            $table->unsignedSmallInteger('calculated_score')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('client_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->string('vin')->nullable()->unique();
            $table->string('plates')->nullable()->unique();
            $table->string('engine_number')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('gps_status')->nullable();
            $table->json('invoice_data')->nullable();
            $table->json('circulation_card')->nullable();
            $table->json('tenure_data')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('available');
            $table->timestamps();
            $table->index(['brand', 'model']);
        });

        Schema::create('simulations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('capital', 15, 2);
            $table->decimal('monthly_rate', 8, 6);
            $table->unsignedSmallInteger('term_months');
            $table->date('start_date');
            $table->unsignedTinyInteger('payment_day');
            $table->unsignedInteger('rounding_increment')->default(10);
            $table->string('rounding_adjustment')->default('first');
            $table->decimal('total_interest', 15, 2);
            $table->decimal('contract_total', 15, 2);
            $table->json('schedule');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('simulation_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('vehicle_price', 15, 2)->nullable();
            $table->decimal('down_payment', 15, 2)->default(0);
            $table->decimal('requested_capital', 15, 2);
            $table->decimal('monthly_rate', 8, 6);
            $table->unsignedSmallInteger('term_months');
            $table->unsignedTinyInteger('payment_day');
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('approved_conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('folio')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_application_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('capital', 15, 2);
            $table->decimal('monthly_rate', 8, 6);
            $table->unsignedSmallInteger('term_months');
            $table->decimal('contract_total', 15, 2);
            $table->date('start_date');
            $table->unsignedTinyInteger('payment_day');
            $table->string('status')->default('formalizing')->index();
            $table->timestamps();
            $table->index(['operator_id', 'status']);
        });

        Schema::create('loan_terms_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('capital', 15, 2);
            $table->decimal('monthly_rate', 8, 6);
            $table->unsignedSmallInteger('term_months');
            $table->decimal('contract_total', 15, 2);
            $table->json('schedule_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['loan_id', 'version']);
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_version_id')->nullable()->constrained('loan_terms_versions')->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('contract_amount', 15, 2);
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2);
            $table->string('status')->default('upcoming')->index();
            $table->timestamps();
            $table->unique(['loan_id', 'number']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('collection_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('folio')->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('operated_on');
            $table->decimal('contract_amount', 15, 2);
            $table->decimal('operator_surcharge_amount', 15, 2)->default(0);
            $table->decimal('external_concepts_amount', 15, 2)->default(0);
            $table->string('type');
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('confirmation_status')->default('reported')->index();
            $table->timestamps();
            $table->index(['loan_id', 'operated_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('installment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['collection_movement_id', 'installment_id']);
        });

        Schema::create('weekly_cuts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->decimal('expected_total', 15, 2)->default(0);
            $table->decimal('reported_total', 15, 2)->default(0);
            $table->decimal('received_total', 15, 2)->default(0);
            $table->decimal('difference_total', 15, 2)->default(0);
            $table->decimal('previous_balance', 15, 2)->default(0);
            $table->decimal('regularization_total', 15, 2)->default(0);
            $table->decimal('accumulated_balance', 15, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['operator_id', 'period_starts_on', 'period_ends_on']);
        });

        Schema::create('weekly_cut_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_cut_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_movement_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_amount', 15, 2);
            $table->decimal('reported_amount', 15, 2);
            $table->decimal('received_amount', 15, 2)->default(0);
            $table->string('status')->default('included');
            $table->timestamps();
            $table->unique(['weekly_cut_id', 'collection_movement_id']);
        });

        Schema::create('operator_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->foreignId('weekly_cut_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('idempotency_key')->unique();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['operator_id', 'created_at']);
        });

        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('loan_type')->default('vehicle');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['name', 'loan_type']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('delivered')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('promissory_notes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->string('physical_location')->nullable();
            $table->string('status')->default('in_custody')->index();
            $table->string('custodian')->nullable();
            $table->timestamps();
            $table->unique(['loan_id', 'number']);
        });

        Schema::create('custody_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promissory_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_custodian')->nullable();
            $table->string('to_custodian');
            $table->string('receiver')->nullable();
            $table->date('received_on')->nullable();
            $table->text('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_quotes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_version');
            $table->json('breakdown');
            $table->decimal('amount', 15, 2);
            $table->date('valid_until');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('settlement_quote_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('investor_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('investor_share_rate', 8, 6);
            $table->decimal('administrator_share_rate', 8, 6);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status')->default('active');
            $table->json('agreement_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('investment_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->json('breakdown')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('related_idempotency_key')->nullable()->index();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('investment_ledger_entries');
        Schema::dropIfExists('investments');
        Schema::dropIfExists('investors');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('settlement_quotes');
        Schema::dropIfExists('custody_events');
        Schema::dropIfExists('promissory_notes');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_requirements');
        Schema::dropIfExists('operator_ledger_entries');
        Schema::dropIfExists('weekly_cut_items');
        Schema::dropIfExists('weekly_cuts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('collection_movements');
        Schema::dropIfExists('installments');
        Schema::dropIfExists('loan_terms_versions');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('application_status_history');
        Schema::dropIfExists('loan_applications');
        Schema::dropIfExists('simulations');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('client_references');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('operators');
    }
};
