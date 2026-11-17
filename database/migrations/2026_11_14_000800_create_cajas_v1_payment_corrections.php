<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_v1_payment_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('correction_id', 80)->unique();
            $table->foreignId('cut_execution_id')->constrained('cajas_v1_cut_executions')->restrictOnDelete();
            $table->foreignId('original_movement_id')->unique()->constrained('collection_movements')->restrictOnDelete();
            $table->foreignId('replacement_movement_id')->unique()->constrained('collection_movements')->restrictOnDelete();
            $table->string('operator', 80);
            $table->char('body_hash', 64);
            $table->text('body');
            $table->json('original_allocation');
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_payment_corrections')->exists()) {
            throw new RuntimeException('La base ya contiene correcciones financieras auditadas.');
        }
        Schema::dropIfExists('cajas_v1_payment_corrections');
    }
};
