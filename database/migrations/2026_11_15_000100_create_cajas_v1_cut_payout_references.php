<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_v1_cut_payout_references', function (Blueprint $table): void {
            $table->string('public_id', 80)->primary();
            $table->string('cut_id', 80)->index();
            $table->string('operator', 80);
            $table->string('kind', 16);
            $table->unsignedInteger('first_version');
            $table->json('details');
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_cut_payout_references')->exists()) {
            throw new RuntimeException('La base contiene referencias auditadas de egresos del corte.');
        }

        Schema::dropIfExists('cajas_v1_cut_payout_references');
    }
};
