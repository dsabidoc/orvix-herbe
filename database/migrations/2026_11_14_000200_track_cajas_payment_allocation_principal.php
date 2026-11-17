<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('principal_cents')->nullable();
            $table->unsignedBigInteger('interest_waived_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn(['principal_cents', 'interest_waived_cents']);
        });
    }
};
