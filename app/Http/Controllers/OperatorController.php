<?php

namespace App\Http\Controllers;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class OperatorController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('operators.manage'), 403);

        $operators = Operator::query()
            ->with(['user.roles'])
            ->withCount(['loans', 'clients'])
            ->orderBy('name')
            ->get();

        return view('operators.index', [
            'operators' => $operators,
            'operatorUsers' => User::query()
                ->role('operador-cartera')
                ->with('operatorProfile')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('operators.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => $data['status'],
                'force_password_change' => true,
            ]);

            $role = Role::findOrCreate('operador-cartera', 'web');
            $user->assignRole($role);

            Operator::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'status' => $user->status === 'active' ? 'active' : 'inactive',
            ]);
        });

        return back()->with('status', 'Operador creado.');
    }
}
