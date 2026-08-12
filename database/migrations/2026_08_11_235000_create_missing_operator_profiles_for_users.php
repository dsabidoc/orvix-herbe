<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'operador-cartera')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $roleId) {
            return;
        }

        $now = Carbon::now();

        DB::table('users')
            ->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.status')
            ->join('model_has_roles', function ($join) use ($roleId): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User')
                    ->where('model_has_roles.role_id', '=', $roleId);
            })
            ->leftJoin('operators', 'operators.user_id', '=', 'users.id')
            ->whereNull('operators.id')
            ->orderBy('users.id')
            ->get()
            ->each(function ($user) use ($now): void {
                DB::table('operators')->insert([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'cut_day' => 6,
                    'tolerance_days' => 0,
                    'max_overdue_installments' => 0,
                    'allows_shortfalls' => false,
                    'assumes_collection_risk' => false,
                    'covers_installment_when_client_misses' => false,
                    'alert_rules' => null,
                    'status' => $user->status === 'active' ? 'active' : 'inactive',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: operator profiles may already be used by loans.
    }
};
