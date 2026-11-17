<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCustodyTable('openings', [['cash_account_id']], function (Blueprint $table): void {
            $table->id();
            $table->string('cash_account_id', 80);
            $table->string('opening_close_id', 80);
            $table->string('cutoff_at', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->string('evidence_ref');
            $table->foreignId('authorized_by');
            $table->string('recorded_at', 32);
        });
        $this->createCustodyTable('movements', [
            ['financial_origin'],
            ['cash_account_id', 'reference', 'source'],
        ], function (Blueprint $table): void {
            $table->id();
            $table->string('cash_account_id', 80);
            $table->string('reference', 80);
            $table->string('source', 20);
            $table->string('financial_origin', 120)->nullable();
            $table->bigInteger('amount_cents');
            $table->string('effective_at', 32);
            $table->string('recorded_at', 32);
            $table->string('evidence_ref');
            $table->string('status', 20);
            $table->foreignId('authorized_by');
        });
        $this->createCustodyTable('closes', [['cash_account_id', 'close_id']], function (Blueprint $table): void {
            $table->id();
            $table->string('close_id', 80);
            $table->string('cash_account_id', 80);
            $table->json('request');
            $table->json('result');
            $table->foreignId('authorized_by');
            $table->string('recorded_at', 32);
        });
    }

    private function createCustodyTable(string $name, array $uniqueKeys, Closure $columns): void
    {
        $tableName = 'cajas_v1_custody_'.$name;
        // Resume an interrupted DDL migration without rebuilding a table that already exists.
        $resuming = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && Schema::hasTable($tableName);
        $definition = function (Blueprint $table) use ($tableName, $uniqueKeys, $columns, $resuming): void {
            if (! $resuming) {
                $columns($table);
            }
            if (! $resuming || ! Schema::hasForeignKey($tableName, ['authorized_by'])) {
                $table->foreign('authorized_by')->references('id')->on('users')->restrictOnDelete();
            }
            foreach ($uniqueKeys as $key) {
                if (! $resuming || ! Schema::hasIndex($tableName, $key, 'unique')) {
                    $index = $key === ['cash_account_id', 'reference', 'source'] ? 'cajas_v1_custody_movement_reference_uq' : null;
                    $table->unique($key, $index);
                }
            }
        };

        if ($resuming) {
            Schema::table($tableName, $definition);
        } else {
            Schema::create($tableName, $definition);
        }
    }

    public function down(): void
    {
        foreach (['closes', 'movements', 'openings'] as $name) {
            if (DB::table('cajas_v1_custody_'.$name)->exists()) {
                throw new RuntimeException('La base contiene evidencia auditada de custodia.');
            }
        }
        foreach (['closes', 'movements', 'openings'] as $name) {
            Schema::drop('cajas_v1_custody_'.$name);
        }
    }
};
