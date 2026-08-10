@php use App\Support\PermissionLabels; @endphp

<x-layouts.app title="Configuracion · Permisos">
    @include('settings._nav')

    <div class="mb-4 flex justify-end">
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-permission-modal">Agregar permiso</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="font-bold text-slate-950">Permisos</h3>
        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($permissions as $permission)
                <div class="rounded-md bg-slate-50 p-3 text-sm">
                    <p class="font-semibold text-slate-950">{{ PermissionLabels::label($permission->name) }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase text-[#0f766e]">{{ PermissionLabels::group($permission->name) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ PermissionLabels::description($permission->name) }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ $permission->name }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <dialog id="create-permission-modal" class="w-[min(92vw,420px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('settings.permissions.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Agregar permiso</h3>
            </div>
            <div class="px-5 py-4">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" placeholder="clave.permiso" required>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Crear permiso</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
