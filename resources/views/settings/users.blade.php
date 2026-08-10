@php use App\Support\PermissionLabels; @endphp

<x-layouts.app title="Configuracion · Usuarios">
    @include('settings._nav')

    <div class="mb-4 flex justify-end">
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-user-modal">Agregar usuario</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Usuarios</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach ($users as $user)
                <div class="flex items-center justify-between gap-3 px-5 py-4 text-sm">
                    <div>
                        <p class="font-semibold text-slate-950">{{ $user->name }}</p>
                        <p class="text-slate-500">{{ $user->email }} · {{ $user->roles->pluck('name')->join(', ') ?: 'sin rol' }} · {{ $user->permissions->map(fn ($permission) => PermissionLabels::label($permission->name))->join(', ') ?: 'sin permisos directos' }} · {{ $user->status === 'active' ? 'Activo' : 'Inactivo' }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="rounded-md border border-slate-300 px-3 py-2 font-semibold" type="button" data-open-modal="edit-user-modal-{{ $user->id }}">Editar</button>
                        <form method="POST" action="{{ route('settings.users.toggle', $user) }}">
                            @csrf
                            <button class="rounded-md border border-slate-300 px-3 py-2 font-semibold">{{ $user->status === 'active' ? 'Desactivar' : 'Activar' }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <dialog id="create-user-modal" class="w-[min(92vw,460px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('settings.users.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Agregar usuario</h3>
            </div>
            <div class="space-y-3 px-5 py-4">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" placeholder="Nombre" required>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="Correo" type="email" required>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="password" placeholder="Password" type="password" required>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="role" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Crear usuario</button>
            </div>
        </form>
    </dialog>

    @foreach ($users as $user)
        <dialog id="edit-user-modal-{{ $user->id }}" class="w-[min(92vw,560px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <form method="POST" action="{{ route('settings.users.update', $user) }}">
                @csrf
                @method('PUT')
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Editar usuario</h3>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Nombre</label>
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" value="{{ $user->name }}" required>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Correo</label>
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" type="email" value="{{ $user->email }}" required>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Nueva contraseña</label>
                        <div class="mt-1 flex gap-2">
                            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="password" type="text" placeholder="Dejar vacio para conservar" data-generated-password>
                            <button class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-generate-password>Generar</button>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Rol</label>
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="role">
                            <option value="">Sin rol</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->name }}" @selected($user->hasRole($role->name))>{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Permisos directos</label>
                        <div class="mt-1 max-h-64 space-y-2 overflow-auto rounded-md border border-slate-200 p-3">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input class="rounded border-slate-300" name="permissions[]" type="checkbox" value="{{ $permission->name }}" @checked($user->hasDirectPermission($permission->name))>
                                    <span>
                                        <span class="block font-semibold">{{ PermissionLabels::label($permission->name) }}</span>
                                        <span class="block text-xs text-slate-500">{{ PermissionLabels::description($permission->name) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                    <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar cambios</button>
                </div>
            </form>
        </dialog>
    @endforeach
</x-layouts.app>
