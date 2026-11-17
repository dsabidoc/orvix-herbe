<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas_v1_pending_credits', function (Blueprint $table) {
            $table->json('funding_hold')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_pending_credits')->whereNotNull('funding_hold')->exists()) {
            throw new RuntimeException('La base contiene bloqueos de fondeo vigentes.');
        }
        Schema::table('cajas_v1_pending_credits', function (Blueprint $table) {
            $table->dropColumn('funding_hold');
        });
    }
};
