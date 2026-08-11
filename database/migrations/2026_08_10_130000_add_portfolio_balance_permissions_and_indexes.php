<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now('America/Merida');

        foreach (['portfolio.view', 'portfolio.export'] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissions = DB::table('permissions')
            ->whereIn('name', ['portfolio.view', 'portfolio.export'])
            ->pluck('id', 'name');
        $roles = DB::table('roles')
            ->whereIn('name', ['administrador-general', 'operador-cartera'])
            ->pluck('id', 'name');

        $this->assignPermission($roles['administrador-general'] ?? null, $permissions['portfolio.view'] ?? null);
        $this->assignPermission($roles['administrador-general'] ?? null, $permissions['portfolio.export'] ?? null);
        $this->assignPermission($roles['operador-cartera'] ?? null, $permissions['portfolio.view'] ?? null);

        Schema::table('installments', function ($table) {
            $table->index(['loan_id', 'due_date', 'remaining_amount'], 'installments_loan_due_remaining_idx');
        });

        Schema::table('collection_movements', function ($table) {
            $table->index(['confirmation_status', 'operated_on'], 'collection_movements_status_date_idx');
        });

        Schema::table('payment_allocations', function ($table) {
            $table->index('installment_id', 'payment_allocations_installment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function ($table) {
            $table->dropIndex('payment_allocations_installment_idx');
        });

        Schema::table('collection_movements', function ($table) {
            $table->dropIndex('collection_movements_status_date_idx');
        });

        Schema::table('installments', function ($table) {
            $table->dropIndex('installments_loan_due_remaining_idx');
        });

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['portfolio.view', 'portfolio.export'])
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('name', ['portfolio.view', 'portfolio.export'])
            ->delete();
    }

    private function assignPermission(?int $roleId, ?int $permissionId): void
    {
        if (! $roleId || ! $permissionId) {
            return;
        }

        DB::table('role_has_permissions')->updateOrInsert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }
};
