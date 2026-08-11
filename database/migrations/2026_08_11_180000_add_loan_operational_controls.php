<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('guarantor_name')->nullable()->after('payment_day');
            $table->text('guarantor_address')->nullable()->after('guarantor_name');
            $table->string('guarantor_phone')->nullable()->after('guarantor_address');
            $table->boolean('is_frozen')->default(false)->after('guarantor_phone')->index();
            $table->text('frozen_reason')->nullable()->after('is_frozen');
            $table->timestamp('frozen_at')->nullable()->after('frozen_reason');
            $table->foreignId('frozen_by')->nullable()->after('frozen_at')->constrained('users')->nullOnDelete();
            $table->decimal('delinquency_rate', 8, 4)->default(0)->after('frozen_by');
            $table->unsignedSmallInteger('delinquency_grace_days')->default(0)->after('delinquency_rate');
            $table->string('invoice_holder')->nullable()->after('delinquency_grace_days');
            $table->foreignId('invoice_document_id')->nullable()->after('invoice_holder')->constrained('documents')->nullOnDelete();
        });

        Schema::table('collection_movements', function (Blueprint $table) {
            $table->decimal('additional_charge_amount', 15, 2)->default(0)->after('external_concepts_amount');
            $table->decimal('delinquency_amount', 15, 2)->default(0)->after('additional_charge_amount');
            $table->foreignId('origin_weekly_cut_id')->nullable()->after('weekly_cut_id')->constrained('weekly_cuts')->nullOnDelete();
        });

        Schema::create('loan_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->timestamps();
            $table->index(['loan_id', 'created_at']);
        });

        Schema::create('loan_invoice_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('from_holder')->nullable();
            $table->string('to_holder');
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_invoice_movements');
        Schema::dropIfExists('loan_notes');

        Schema::table('collection_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_weekly_cut_id');
            $table->dropColumn(['additional_charge_amount', 'delinquency_amount']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_document_id');
            $table->dropConstrainedForeignId('frozen_by');
            $table->dropColumn([
                'guarantor_name',
                'guarantor_address',
                'guarantor_phone',
                'is_frozen',
                'frozen_reason',
                'frozen_at',
                'delinquency_rate',
                'delinquency_grace_days',
                'invoice_holder',
            ]);
        });
    }
};
