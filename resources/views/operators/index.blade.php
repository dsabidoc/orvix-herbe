<x-layouts.app title="Operadores">
    <div class="mb-4 flex justify-end">
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-operator-modal">Agregar operador</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Usuarios operadores</h3>
            <p class="mt-1 text-sm text-slate-500">Usuarios con rol operador y su perfil operativo para cartera.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Operador</th>
                        <th class="px-5 py-3">Contacto</th>
                        <th class="px-5 py-3 text-right">Clientes</th>
                        <th class="px-5 py-3 text-right">Prestamos</th>
                        <th class="px-5 py-3">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($operators as $operator)
                        <tr>
                            <td class="px-5 py-3">
                                <p class="font-bold text-[#0f766e]">{{ $operator->name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $operator->user ? 'Usuario ligado' : 'Sin usuario ligado' }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-500">{{ $operator->email ?: 'Sin correo' }} · {{ $operator->phone ?: 'Sin celular' }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ $operator->clients_count }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ $operator->loans_count }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded px-2 py-1 text-xs font-bold {{ $operator->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ $operator->status === 'active' ? 'Activo' : 'Inactivo' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-8 text-slate-500" colspan="5">No hay operadores registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Usuarios con rol operador</h3>
            <p class="mt-1 text-sm text-slate-500">Referencia rápida de accesos creados con rol operador-cartera.</p>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse ($operatorUsers as $user)
                <div class="flex flex-col gap-2 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-semibold text-slate-950">{{ $user->name }}</p>
                        <p class="text-slate-500">{{ $user->email }} · {{ $user->phone ?: 'Sin celular' }}</p>
                    </div>
                    <span class="w-fit rounded px-2 py-1 text-xs font-bold {{ $user->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ $user->status === 'active' ? 'Activo' : 'Inactivo' }}</span>
                </div>
            @empty
                <p class="p-5 text-sm text-slate-500">No hay usuarios con rol operador.</p>
            @endforelse
        </div>
    </section>

    <dialog id="create-operator-modal" class="w-[min(92vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('operators.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Nuevo operador</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Crear acceso de operador</h3>
            </div>
            <div class="space-y-3 px-5 py-4">
                <label class="block text-sm font-semibold text-slate-700">Nombre
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="name" placeholder="Nombre completo" required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Correo
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="correo@dominio.com" type="email" required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Celular
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" placeholder="Opcional">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Contraseña generica
                    <div class="mt-1 flex gap-2">
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="password" type="text" value="orvix-demo" data-generated-password required>
                        <button class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-generate-password>Generar</button>
                    </div>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Estado
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="status" required>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </label>
                <p class="text-xs text-slate-500">Este modulo siempre crea el usuario con rol operador-cartera.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Crear operador</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
