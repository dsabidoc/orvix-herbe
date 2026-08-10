<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('interest_calculation_method')->default('fixed_principal')->after('monthly_rate');
        });

        Schema::table('simulations', function (Blueprint $table) {
            $table->string('interest_calculation_method')->default('fixed_principal')->after('rate_type');
        });
    }

    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn('interest_calculation_method');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('interest_calculation_method');
        });
    }
};
