<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        'investors.manage',
        'investments.view-own',
        'investor-reports.view-own',
        'investor-withdrawals.request',
        'investor-withdrawals.manage',
    ];

    public function up(): void
    {
        $now = now('America/Merida');

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        DB::table('roles')->updateOrInsert(
            ['name' => 'inversionista', 'guard_name' => 'web'],
            ['created_at' => $now, 'updated_at' => $now],
        );

        $permissions = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id', 'name');
        $roles = DB::table('roles')
            ->whereIn('name', ['administrador-general', 'inversionista'])
            ->pluck('id', 'name');

        foreach (['investors.manage', 'investor-withdrawals.manage'] as $permission) {
            $this->assignPermission($roles['administrador-general'] ?? null, $permissions[$permission] ?? null);
        }

        foreach (['investments.view-own', 'investor-reports.view-own', 'investor-withdrawals.request'] as $permission) {
            $this->assignPermission($roles['inversionista'] ?? null, $permissions[$permission] ?? null);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('name', $this->permissions)
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
