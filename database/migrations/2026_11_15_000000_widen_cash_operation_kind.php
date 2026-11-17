<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->usesMariaDbOrMySql()) {
            Schema::table('cash_operations', fn (Blueprint $table) => $table->string('kind', 32)->change());
        }
    }

    public function down(): void
    {
        if (! $this->usesMariaDbOrMySql()) {
            return;
        }

        if (DB::table('cash_operations')->whereRaw('CHAR_LENGTH(kind) > 20')->exists()) {
            throw new RuntimeException('El historial contiene tipos de operación que no caben en la columna anterior.');
        }

        Schema::table('cash_operations', fn (Blueprint $table) => $table->string('kind', 20)->change());
    }

    private function usesMariaDbOrMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
    }
};
