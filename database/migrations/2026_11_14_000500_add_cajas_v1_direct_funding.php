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
            $table->string('offset_id', 80)->nullable()->change();
            $table->json('offset')->nullable()->change();
            $table->string('disbursement_id', 80)->nullable()->unique();
            $table->json('disbursement')->nullable();
        });

        Schema::create('cajas_v1_funding_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->foreignId('pending_credit_id')->constrained('cajas_v1_pending_credits')->restrictOnDelete();
            $table->char('body_hash', 64);
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_pending_credits')->whereNull('offset_id')->exists()) {
            throw new RuntimeException('Estas propuestas usan el esquema de fondeo directo.');
        }
        Schema::dropIfExists('cajas_v1_funding_events');
        Schema::table('cajas_v1_pending_credits', function (Blueprint $table) {
            $table->dropUnique(['disbursement_id']);
            $table->dropColumn(['disbursement_id', 'disbursement']);
            $table->string('offset_id', 80)->nullable(false)->change();
            $table->json('offset')->nullable(false)->change();
        });
    }
};
