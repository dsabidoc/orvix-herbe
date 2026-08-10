<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('administration_fee_type')->default('monthly')->after('administration_fee');
        });

        Schema::table('simulations', function (Blueprint $table) {
            $table->string('administration_fee_type')->default('monthly')->after('administration_fee');
        });

        Schema::table('loan_applications', function (Blueprint $table) {
            $table->string('administration_fee_type')->default('monthly')->after('administration_fee');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn('administration_fee_type');
        });

        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn('administration_fee_type');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('administration_fee_type');
        });
    }
};
