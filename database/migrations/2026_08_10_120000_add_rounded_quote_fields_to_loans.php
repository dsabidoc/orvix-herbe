<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('calculation_method')->default('regular')->after('loan_application_id');
            $table->unsignedSmallInteger('rounding_multiple')->nullable()->after('interest_calculation_method');
            $table->decimal('interest_monthly', 15, 2)->default(0)->after('rounding_multiple');
            $table->decimal('interest_total', 15, 2)->default(0)->after('interest_monthly');
            $table->decimal('collection_total', 15, 2)->default(0)->after('interest_total');
            $table->decimal('first_payment_amount', 15, 2)->nullable()->after('collection_total');
            $table->decimal('regular_payment_amount', 15, 2)->nullable()->after('first_payment_amount');
            $table->json('quote_snapshot')->nullable()->after('regular_payment_amount');
            $table->foreignId('quoted_by')->nullable()->after('quote_snapshot')->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable()->after('quoted_by');
            $table->foreignId('confirmed_by')->nullable()->after('quoted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('quoted_by');
            $table->dropColumn([
                'calculation_method',
                'rounding_multiple',
                'interest_monthly',
                'interest_total',
                'collection_total',
                'first_payment_amount',
                'regular_payment_amount',
                'quote_snapshot',
                'quoted_at',
                'confirmed_at',
            ]);
        });
    }
};
