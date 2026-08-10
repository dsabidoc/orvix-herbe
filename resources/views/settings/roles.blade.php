@php use App\Support\PermissionLabels; @endphp

<x-layouts.app title="Configuracion · Roles">
    @include('settings._nav')

    <div class="mb-4 flex justify-end">
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-role-modal">Agregar rol</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Roles</h3>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach ($roles as $role)
                <div class="flex items-center justify-between gap-3 px-5 py-4 text-sm">
                    <div>
                        <p class="font-semibold text-slate-950">{{ $role->name }}</p>
                        <p class="text-slate-500">{{ $role->permissions_count }} permisos · {{ $role->permissions->take(4)->map(fn ($permission) => PermissionLabels::label($permission->name))->join(', ') }}{{ $role->permissions_count > 4 ? '...' : '' }}</p>
                    </div>
                    <button class="rounded-md border border-slate-300 px-3 py-2 font-semibold" type="button" data-open-modal="edit-role-modal-{{ $role->id }}">Editar</button>
                </div>
            @endforeach
        </div>
    </section>

    <dialog id="create-role-modal" class="w-[min(92vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('settings.roles.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Agregar rol</h3>
            </div>
            <div class="space-y-3 px-5 py-4">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" placeholder="Nombre del rol" required>
                <div class="max-h-72 space-y-2 overflow-auto rounded-md border border-slate-200 p-3">
                    @foreach ($permissions as $permission)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input class="rounded border-slate-300" name="permissions[]" type="checkbox" value="{{ $permission->name }}">
                            <span>
                                <span class="block font-semibold">{{ PermissionLabels::label($permission->name) }}</span>
                                <span class="block text-xs text-slate-500">{{ PermissionLabels::description($permission->name) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Crear rol</button>
            </div>
        </form>
    </dialog>

    @foreach ($roles as $role)
        <dialog id="edit-role-modal-{{ $role->id }}" class="w-[min(92vw,560px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <form method="POST" action="{{ route('settings.roles.update', $role) }}">
                @csrf
                @method('PUT')
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Editar rol</h3>
                </div>
                <div class="space-y-3 px-5 py-4">
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Nombre del rol</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" value="{{ $role->name }}" required>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Permisos</label>
                        <div class="mt-1 max-h-72 space-y-2 overflow-auto rounded-md border border-slate-200 p-3">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input class="rounded border-slate-300" name="permissions[]" type="checkbox" value="{{ $permission->name }}" @checked($role->hasPermissionTo($permission->name))>
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
