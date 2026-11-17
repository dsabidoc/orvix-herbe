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
        DB::table('installments')->whereNull('public_id')->select('id')->chunkById(500, function ($letters): void {
            foreach ($letters as $letter) {
                DB::table('installments')->where('id', $letter->id)->whereNull('public_id')
                    ->update(['public_id' => (string) Str::ulid()]);
            }
        });

        Schema::table('installments', fn (Blueprint $table) => $table->ulid('public_id')->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('installments', fn (Blueprint $table) => $table->ulid('public_id')->nullable()->change());
    }
};
