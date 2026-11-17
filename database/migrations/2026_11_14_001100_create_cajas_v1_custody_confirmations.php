<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_v1_custody_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('cash_movement_id', 80)->unique();
            $table->string('cash_account_id', 80);
            $table->foreignId('custody_movement_id')->unique()->constrained('cajas_v1_custody_movements')->restrictOnDelete();
            $table->char('body_hash', 64);
            $table->text('body');
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_custody_confirmations')->exists()) {
            throw new RuntimeException('La base contiene confirmaciones auditadas de custodia.');
        }
        Schema::dropIfExists('cajas_v1_custody_confirmations');
    }
};
