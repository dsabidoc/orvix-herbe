<?php

use App\Models\User;
use Database\Seeders\CajasPermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve entry for existing users, including custom legacy profiles.
        // New Cajas users receive no entry to Préstamos unless explicitly granted.
        $permission = Permission::findOrCreate('prestamos.access', 'web');
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($permission): void {
            foreach ($users as $user) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permission->id,
                    'model_type' => (new User)->getMorphClass(),
                    'model_id' => $user->id,
                ]);
            }
        });
        (new CajasPermissionSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally retain grants: they may have been customized after rollout.
    }
};
