<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_allocations', 'interest_waived_cents')) {
            Schema::table('payment_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('interest_waived_cents')->nullable();
            });
        }
    }

    public function down(): void
    {
        // The earlier migration owns the column in fresh databases. Keep it and its data on rollback.
    }
};
