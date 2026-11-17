<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->decimal('collection_fee_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('collection_movements', function (Blueprint $table) {
            $table->dropColumn('collection_fee_amount');
        });
    }
};
