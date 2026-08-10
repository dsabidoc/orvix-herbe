<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->string('rate_type')->default('monthly')->after('monthly_rate');
            $table->string('opening_fee_type')->default('none')->after('rounding_adjustment');
            $table->decimal('opening_fee_value', 15, 6)->default(0)->after('opening_fee_type');
            $table->decimal('opening_fee_amount', 15, 2)->default(0)->after('opening_fee_value');
        });
    }

    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn(['rate_type', 'opening_fee_type', 'opening_fee_value', 'opening_fee_amount']);
        });
    }
};
