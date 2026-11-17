<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_v1_correction_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('correction_id', 80)->unique();
            $table->string('cut_id', 80);
            $table->string('operator', 80);
            $table->char('body_hash', 64);
            $table->text('body');
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cajas_v1_correction_rejections')->exists()) {
            throw new RuntimeException('La base contiene rechazos de correcciones auditados.');
        }
        Schema::dropIfExists('cajas_v1_correction_rejections');
    }
};
