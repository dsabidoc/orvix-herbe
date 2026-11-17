<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', fn (Blueprint $table) => $table->ulid('public_id')->nullable()->unique());
        DB::table('installments')->select('id')->chunkById(500, function ($letters): void {
            foreach ($letters as $letter) {
                DB::table('installments')->where('id', $letter->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });
        Schema::create('cajas_v1_installment_replacements', function (Blueprint $table): void {
            $table->ulid('public_id')->primary();
            $table->ulid('loan_public_id')->index();
            $table->ulid('replacement_public_id')->nullable();
        });
        Schema::create('cajas_v1_cut_executions', function (Blueprint $table): void {
            $table->id();
            $table->string('cut_id', 80);
            $table->unsignedInteger('version');
            $table->string('operator', 80);
            $table->string('status', 24)->index();
            $table->char('body_hash', 64);
            $table->longText('body');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['cut_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas_v1_cut_executions');
        Schema::dropIfExists('cajas_v1_installment_replacements');
        Schema::table('installments', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
