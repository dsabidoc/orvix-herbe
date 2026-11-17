<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'cash_disbursement_corrections';
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && Schema::hasTable($tableName)) {
            // MySQL and MariaDB may have committed the table before a constraint failed.
            Schema::table($tableName, fn (Blueprint $table) => $this->addConstraints($table, resuming: true));
        } else {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id');
                $table->foreignId('cash_collection_cut_id');
                $table->foreignId('active_cut_id')->nullable();
                $table->foreignId('cash_pending_disbursement_id');
                $table->unsignedInteger('base_version');
                $table->string('kind');
                $table->string('status')->default('pending');
                $table->text('reason');
                $table->text('evidence');
                $table->json('input');
                $table->json('proposal');
                $table->foreignId('created_by');
                $table->foreignId('resolved_by')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('cash_operation_id')->nullable();
                $table->timestamps();

                $this->addConstraints($table);
            });
        }
        foreach (['cajas.disbursements.correct-request', 'cajas.disbursements.correct-resolve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function addConstraints(Blueprint $table, bool $resuming = false): void
    {
        foreach (['public_id', 'active_cut_id', 'cash_operation_id'] as $column) {
            if (! $resuming || ! Schema::hasIndex($table->getTable(), [$column], 'unique')) {
                $table->unique($column);
            }
        }

        $references = [
            'cash_collection_cut_id' => 'cash_collection_cuts',
            'active_cut_id' => 'cash_collection_cuts',
            'cash_pending_disbursement_id' => 'cash_pending_disbursements',
            'created_by' => 'users',
            'resolved_by' => 'users',
            'cash_operation_id' => 'cash_operations',
        ];
        foreach ($references as $column => $parent) {
            if ($resuming && Schema::hasForeignKey($table->getTable(), [$column])) {
                continue;
            }
            $name = $column === 'cash_pending_disbursement_id' ? 'cash_disbursement_corrections_pending_fk' : null;
            $table->foreign($column, $name)->references('id')->on($parent)->restrictOnDelete();
        }
    }

    public function down(): void
    {
        if (DB::table('cash_disbursement_corrections')->exists()) {
            throw new RuntimeException('No se puede eliminar el historial de correcciones de capital.');
        }
        Schema::dropIfExists('cash_disbursement_corrections');
    }
};
