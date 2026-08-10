<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('vat_enabled')->default(true)->after('administration_fee_type');
        });

        Schema::table('simulations', function (Blueprint $table) {
            $table->boolean('vat_enabled')->default(true)->after('administration_fee_type');
        });

        Schema::table('loan_applications', function (Blueprint $table) {
            $table->boolean('vat_enabled')->default(true)->after('administration_fee_type');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn('vat_enabled');
        });

        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn('vat_enabled');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('vat_enabled');
        });
    }
};
