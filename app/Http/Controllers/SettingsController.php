<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\FundDisbursement;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\Operator;
use App\Models\Simulation;
use App\Models\User;
use App\Models\Vehicle;
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

    public function clientMerge(Request $request): View
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $query = Client::query()
            ->with('operator')
            ->withCount(['loans', 'loanApplications', 'vehicles', 'documents'])
            ->where('status', '!=', 'merged');

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($query) => $query
                ->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('phone', 'like', $search)
                ->orWhere('email', 'like', $search));
        }

        return view('settings.client-merge', [
            'clients' => $query
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(40)
                ->withQueryString(),
        ]);
    }

    public function mergeClients(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $data = $request->validate([
            'primary_client_id' => ['required', 'exists:clients,id'],
            'duplicate_client_ids' => ['required', 'array', 'min:1'],
            'duplicate_client_ids.*' => ['integer', 'exists:clients,id'],
        ], [
            'primary_client_id.required' => 'Selecciona el cliente principal.',
            'duplicate_client_ids.required' => 'Selecciona al menos un cliente duplicado para unificar.',
        ]);

        $duplicateIds = collect($data['duplicate_client_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $primaryId = (int) $data['primary_client_id'];

        if ($duplicateIds->contains($primaryId)) {
            return back()
                ->withErrors(['duplicate_client_ids' => 'El cliente principal no puede estar tambien como duplicado.'])
                ->withInput();
        }

        if (Client::query()->whereKey($primaryId)->where('status', 'merged')->exists()) {
            return back()
                ->withErrors(['primary_client_id' => 'El cliente principal no puede ser un cliente ya fusionado.'])
                ->withInput();
        }

        DB::transaction(function () use ($primaryId, $duplicateIds, $request): void {
            $primary = Client::query()->lockForUpdate()->findOrFail($primaryId);
            $duplicates = Client::query()
                ->whereIn('id', $duplicateIds)
                ->where('status', '!=', 'merged')
                ->lockForUpdate()
                ->get();

            $before = [
                'primary' => $this->clientAuditSnapshot($primary),
                'duplicates' => $duplicates->map(fn (Client $client) => $this->clientAuditSnapshot($client))->values()->all(),
            ];

            foreach ($duplicates as $duplicate) {
                Loan::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);
                LoanApplication::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);
                Vehicle::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);
                Document::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);
                Simulation::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);
                FundDisbursement::query()->where('client_id', $duplicate->id)->update(['client_id' => $primary->id]);

                $this->fillMissingClientData($primary, $duplicate);

                $duplicate->update([
                    'status' => 'merged',
                    'notes' => trim(($duplicate->notes ? $duplicate->notes."\n\n" : '').'Unificado con cliente '.$primary->first_name.' '.$primary->last_name.' el '.now('America/Merida')->format('d/m/Y H:i').'.'),
                ]);
            }

            $primary->save();

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'clients.merged',
                'auditable_type' => Client::class,
                'auditable_id' => $primary->id,
                'before' => $before,
                'after' => [
                    'primary' => $this->clientAuditSnapshot($primary->refresh()),
                    'merged_client_ids' => $duplicates->pluck('id')->values()->all(),
                ],
                'reason' => 'Unificacion manual de clientes duplicados desde configuracion.',
            ]);
        });

        return redirect()
            ->route('settings.client-merge', ['q' => $request->input('q')])
            ->with('status', 'Clientes unificados. Los prestamos y expedientes ahora apuntan al cliente principal.');
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

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'No puedes eliminar tu propio usuario mientras estas en sesion.']);
        }

        DB::transaction(function () use ($user): void {
            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();
        });

        return back()->with('status', 'Usuario eliminado.');
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

    private function fillMissingClientData(Client $primary, Client $duplicate): void
    {
        foreach (['operator_id', 'phone', 'alternate_phone', 'email', 'curp', 'rfc', 'identification_type', 'identification_last4'] as $field) {
            if (blank($primary->{$field}) && filled($duplicate->{$field})) {
                $primary->{$field} = $duplicate->{$field};
            }
        }

        if (blank($primary->address) && filled($duplicate->address)) {
            $primary->address = $duplicate->address;
        }

        $mergeNote = 'Fusionado con '.$duplicate->first_name.' '.$duplicate->last_name.' (#'.$duplicate->id.').';
        $primary->notes = trim(($primary->notes ? $primary->notes."\n" : '').$mergeNote);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAuditSnapshot(Client $client): array
    {
        return [
            'id' => $client->id,
            'public_id' => $client->public_id,
            'name' => trim($client->first_name.' '.$client->last_name),
            'phone' => $client->phone,
            'email' => $client->email,
            'status' => $client->status,
        ];
    }
}
