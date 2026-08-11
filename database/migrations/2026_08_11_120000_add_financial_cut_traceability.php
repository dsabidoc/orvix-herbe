<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_cuts', function (Blueprint $table) {
            $table->date('settlement_on')->nullable()->after('period_ends_on');
            $table->decimal('confirmed_total', 15, 2)->default(0)->after('received_total');
            $table->decimal('funds_delivered_total', 15, 2)->default(0)->after('regularization_total');
            $table->decimal('adjustments_in_total', 15, 2)->default(0)->after('funds_delivered_total');
            $table->decimal('adjustments_out_total', 15, 2)->default(0)->after('adjustments_in_total');
            $table->decimal('net_result_total', 15, 2)->default(0)->after('adjustments_out_total');
            $table->timestamp('closed_at')->nullable()->after('balance_settled_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable()->after('closed_by');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable()->after('reopened_by');
        });

        Schema::table('collection_movements', function (Blueprint $table) {
            $table->foreignId('weekly_cut_id')->nullable()->after('operator_id')->constrained()->nullOnDelete();
            $table->timestamp('registered_at')->nullable()->after('operated_on')->index();
            $table->foreignId('reversed_movement_id')->nullable()->after('confirmed_by')->constrained('collection_movements')->nullOnDelete();
            $table->index(['operator_id', 'weekly_cut_id']);
        });

        Schema::create('fund_disbursements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->foreignId('weekly_cut_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('delivered_on');
            $table->timestamp('registered_at');
            $table->string('capital_source')->nullable();
            $table->text('notes')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('registered')->index();
            $table->boolean('is_delivery_date_inferred')->default(false);
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['operator_id', 'delivered_on']);
            $table->index(['weekly_cut_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_disbursements');

        Schema::table('collection_movements', function (Blueprint $table) {
            $table->dropIndex(['operator_id', 'weekly_cut_id']);
            $table->dropConstrainedForeignId('reversed_movement_id');
            $table->dropColumn('registered_at');
            $table->dropConstrainedForeignId('weekly_cut_id');
        });

        Schema::table('weekly_cuts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'settlement_on',
                'confirmed_total',
                'funds_delivered_total',
                'adjustments_in_total',
                'adjustments_out_total',
                'net_result_total',
                'closed_at',
                'reopened_at',
                'reopen_reason',
            ]);
        });
    }
};
