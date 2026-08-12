@php
    use App\Support\Money;

    $investorUserOptions = ($investorUsers ?? collect())->mapWithKeys(function ($user) {
        $parts = preg_split('/\s+/', trim($user->name), 2);

        return [
            $user->id => [
                'first_name' => $parts[0] ?? $user->name,
                'last_name' => $parts[1] ?? '',
                'email' => $user->email,
                'phone' => $user->phone ?? '',
            ],
        ];
    });
@endphp

<x-layouts.app title="Inversionistas">
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <form class="flex flex-1 gap-2" method="GET">
            <div class="flex-1">
                <label class="text-sm font-semibold text-slate-700" for="q">Buscar inversionista</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" value="{{ request('q') }}" placeholder="Nombre, correo o celular">
            </div>
            <button class="mt-6 rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Filtrar</button>
        </form>
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-investor-modal">Nuevo inversionista</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[920px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Inversionista</th>
                        <th class="px-5 py-3">Contacto</th>
                        <th class="px-5 py-3 text-right">Capital inicial</th>
                        <th class="px-5 py-3 text-right">Capital disponible</th>
                        <th class="px-5 py-3 text-right">Retornos por reinvertir</th>
                        <th class="px-5 py-3 text-right">Prestamos</th>
                        <th class="px-5 py-3">Estado</th>
                        <th class="px-5 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($investors as $investor)
                        <tr>
                            <td class="px-5 py-3">
                                <a class="font-bold text-[#0f766e]" href="{{ route('investors.show', $investor) }}">{{ $investor->name }}</a>
                            </td>
                            <td class="px-5 py-3 text-slate-500">{{ $investor->email ?: 'Sin correo' }} · {{ $investor->phone ?: 'Sin celular' }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($investor->initial_capital) }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($investor->available_capital) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::mxn(Money::decimal(Money::cents($investor->returned_capital_balance) + Money::cents($investor->generated_interest_balance))) }}</td>
                            <td class="px-5 py-3 text-right">{{ $investor->investments_count }}</td>
                            <td class="px-5 py-3"><span class="rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ $investor->status === 'active' ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <button class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700" type="button" data-open-modal="edit-investor-modal-{{ $investor->id }}">Editar</button>
                                    <form method="POST" action="{{ route('investors.destroy', $investor) }}" data-confirm-delete data-confirm-title="¿Eliminar este inversionista?" data-confirm-message="Solo se permite eliminarlo si no tiene prestamos activos vivos. El historial permanecera resguardado.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-8 text-slate-500" colspan="8">No hay inversionistas registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $investors->links() }}</div>

    @foreach ($investors as $investor)
        <dialog id="edit-investor-modal-{{ $investor->id }}" class="w-[min(94vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <form method="POST" action="{{ route('investors.update', $investor) }}">
                @csrf
                @method('PUT')
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Editar inversionista</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $investor->name }}</h3>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <label class="block text-sm font-semibold text-slate-700">Capital inicial
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="initial_capital" type="number" step="0.01" min="0" value="{{ old('initial_capital', $investor->initial_capital) }}">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Capital disponible
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="available_capital" type="number" step="0.01" value="{{ old('available_capital', $investor->available_capital) }}">
                    </label>
                    <p class="text-xs text-slate-500">El capital disponible se ajusta directo y queda registrado en movimientos. Puede ser negativo durante la carga inicial si el capital asignado supera el saldo capturado.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                    <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar cambios</button>
                </div>
            </form>
        </dialog>
    @endforeach

    <dialog id="create-investor-modal" class="w-[min(94vw,640px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('investors.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Nuevo inversionista</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Alta de capital</h3>
            </div>
            <div class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="text-sm font-semibold text-slate-700">Usuario existente</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="user_id" data-investor-user-select data-investor-users='@json($investorUserOptions)'>
                        <option value="">Crear inversionista sin usuario ligado</option>
                        @foreach ($investorUsers ?? [] as $user)
                            <option value="{{ $user->id }}" @selected((string) old('user_id') === (string) $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Si la persona ya existe como usuario, seleccionala aqui para precargar sus datos y ligar su perfil de inversionista.</p>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Nombre</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" value="{{ old('first_name') }}" data-investor-user-field="first_name" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Apellidos</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" value="{{ old('last_name') }}" data-investor-user-field="last_name">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Correo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" type="email" value="{{ old('email') }}" data-investor-user-field="email">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Celular</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" value="{{ old('phone') }}" data-investor-user-field="phone">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Capital inicial</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="initial_capital" type="number" step="0.01" min="0" value="{{ old('initial_capital', '0') }}">
                </div>
                <label class="mt-7 flex items-center gap-2 text-sm font-semibold text-slate-700" data-create-investor-user-row>
                    <input class="rounded border-slate-300 text-[#0d9488]" name="create_user" type="checkbox" value="1" @checked(old('create_user'))>
                    Crear usuario inversionista
                </label>
                <div class="sm:col-span-2" data-create-investor-password-row>
                    <label class="text-sm font-semibold text-slate-700">Contraseña generica</label>
                    <div class="mt-1 flex gap-2">
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="password" data-generated-password value="{{ old('password', 'orvix-demo') }}">
                        <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-generate-password>Generar</button>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar inversionista</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
