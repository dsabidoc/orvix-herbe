<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'administrador-general' => [
            'users.manage',
            'settings.manage',
            'operators.manage',
            'clients.view-all',
            'clients.manage',
            'vehicles.manage',
            'applications.authorize',
            'loans.formalize',
            'payments.confirm',
            'weekly-cuts.confirm',
            'adjustments.approve',
            'settlements.authorize',
            'reports.view-all',
            'audit.view',
            'exports.run',
        ],
        'operador-cartera' => [
            'clients.view-assigned',
            'clients.create',
            'vehicles.view-assigned',
            'applications.create',
            'loans.view-assigned',
            'installments.view-assigned',
            'payments.report',
            'weekly-cuts.prepare',
            'weekly-cuts.submit',
            'operator-ledger.view-own',
        ],
        'responsable-documental' => [
            'clients.view-all',
            'documents.manage',
            'promissory-notes.manage',
            'settlements.prepare-documents',
            'payments.report-authorized',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = collect($this->rolePermissions)
            ->flatten()
            ->unique();

        $permissionNames->each(fn (string $permission) => Permission::findOrCreate($permission, 'web'));

        foreach ($this->rolePermissions as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(
                Permission::query()
                    ->whereIn('name', $permissions)
                    ->where('guard_name', 'web')
                    ->get()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
