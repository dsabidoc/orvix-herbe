<?php

namespace App\Http\Controllers;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SettingsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return Redirect::route('settings.users');
    }

    public function users(Request $request): View
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return view('settings.users', [
            'users' => User::query()->with(['roles', 'permissions'])->orderBy('name')->get(),
            'roles' => Role::query()->withCount('permissions')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function roles(Request $request): View
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return view('settings.roles', [
            'roles' => Role::query()->with('permissions')->withCount('permissions')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function permissions(Request $request): View
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return view('settings.permissions', [
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'exists:roles,name'],
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);
            $user->assignRole($data['role']);
            $this->syncOperatorProfile($user);
        });

        return back()->with('status', 'Usuario creado.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['nullable', 'exists:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $update = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        if (! empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
            $update['force_password_change'] = true;
        }

        DB::transaction(function () use ($user, $update, $data): void {
            $user->update($update);
            $user->syncRoles(array_filter([$data['role'] ?? null]));
            $user->syncPermissions($data['permissions'] ?? []);
            $this->syncOperatorProfile($user->refresh());
        });

        return back()->with('status', 'Usuario actualizado.');
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        DB::transaction(function () use ($user): void {
            $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);
            $this->syncOperatorProfile($user->refresh());
        });

        return back()->with('status', 'Usuario actualizado.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('status', 'Rol creado.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('status', 'Rol actualizado.');
    }

    public function storePermission(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:permissions,name'],
        ]);

        Permission::create(['name' => $data['name'], 'guard_name' => 'web']);

        return back()->with('status', 'Permiso creado.');
    }

    private function syncOperatorProfile(User $user): void
    {
        $operator = Operator::query()->firstOrNew(['user_id' => $user->id]);

        if (! $user->hasRole('operador-cartera')) {
            if ($operator->exists) {
                $operator->update(['status' => 'inactive']);
            }

            return;
        }

        $operator->fill([
            'public_id' => $operator->public_id ?: (string) Str::ulid(),
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'status' => $user->status === 'active' ? 'active' : 'inactive',
        ]);

        $operator->save();
    }
}
