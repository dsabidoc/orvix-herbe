<?php

namespace Database\Seeders;

use App\Domain\Cajas\CajasPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CajasPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([...CajasPermissions::READ, ...CajasPermissions::MANAGE, ...CajasPermissions::SOURCES, 'prestamos.access'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Additive: rerunning never strips customized permissions or assigns people.
        Role::findOrCreate('cajas-operacion', 'web')->givePermissionTo(CajasPermissions::READ);
        Role::findOrCreate('cajas-supervision', 'web')->givePermissionTo([...CajasPermissions::READ, ...CajasPermissions::MANAGE]);

        foreach (['administrador-general', 'operador-cartera', 'responsable-documental', 'inversionista'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo('prestamos.access');
        }
        Role::findOrCreate('administrador-general', 'web')->givePermissionTo([...CajasPermissions::READ, ...CajasPermissions::MANAGE]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
