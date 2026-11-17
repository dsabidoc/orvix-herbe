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
            $table->unsignedInteger('funding_cancelled_version')->nullable();
        });
        Schema::table('cajas_v1_funding_events', function (Blueprint $table) {
            $table->string('reconciliation_id', 80)->nullable()->unique();
            $table->string('reconciled_reference_id', 80)->nullable()->unique();
            $table->json('reconciliation_history')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_funding_events')->whereNotNull('reconciliation_id')->exists()) {
            throw new RuntimeException('La base contiene cancelaciones de fondeo conciliadas.');
        }
        Schema::table('cajas_v1_funding_events', function (Blueprint $table) {
            $table->dropUnique(['reconciliation_id']);
            $table->dropUnique(['reconciled_reference_id']);
            $table->dropColumn(['reconciliation_id', 'reconciled_reference_id', 'reconciliation_history']);
        });
        Schema::table('cajas_v1_pending_credits', function (Blueprint $table) {
            $table->dropColumn('funding_cancelled_version');
        });
    }
};
