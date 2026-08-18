<x-layouts.app title="Configuracion · Unificar clientes">
    @include('settings._nav')

    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
        <p class="font-bold">Unificar no borra clientes.</p>
        <p class="mt-1">Selecciona un cliente principal y marca los duplicados. Los prestamos, vehiculos, solicitudes, expedientes, simulaciones y desembolsos se moveran al principal; los duplicados quedaran como fusionados.</p>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <form class="grid gap-3 md:grid-cols-[1fr_220px_auto]" method="GET" action="{{ route('settings.client-merge') }}">
            <label class="text-sm font-semibold text-slate-700">
                Buscar cliente
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="q" placeholder="Nombre, celular o correo" value="{{ request('q') }}">
            </label>
            <label class="text-sm font-semibold text-slate-700">
                Orden
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="sort">
                    <option value="az" @selected(($sort ?? request('sort', 'az')) === 'az')>A-Z</option>
                    <option value="za" @selected(($sort ?? request('sort')) === 'za')>Z-A</option>
                    <option value="recientes" @selected(($sort ?? request('sort')) === 'recientes')>Mas recientes</option>
                </select>
            </label>
            <button class="self-end rounded-md bg-[#0d9488] px-5 py-2 text-sm font-bold text-white">Buscar</button>
        </form>
    </section>

    <form class="mt-5" method="POST" action="{{ route('settings.client-merge.store') }}" data-client-merge-form>
        @csrf
        <input name="q" type="hidden" value="{{ request('q') }}">
        <input name="sort" type="hidden" value="{{ $sort ?? request('sort') }}">

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-3">
                @include('partials.table-pagination', ['paginator' => $clients])
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="w-28 px-5 py-3">Principal</th>
                            <th class="w-28 px-5 py-3">Duplicado</th>
                            <th class="px-5 py-3">Cliente</th>
                            <th class="px-5 py-3">Operador</th>
                            <th class="px-5 py-3 text-right">Prestamos</th>
                            <th class="px-5 py-3 text-right">Solicitudes</th>
                            <th class="px-5 py-3 text-right">Vehiculos</th>
                            <th class="px-5 py-3 text-right">Expedientes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($clients as $client)
                            <tr>
                                <td class="px-5 py-3">
                                    <input class="size-4 rounded-full border-slate-300 text-[#0d9488]" name="primary_client_id" type="radio" value="{{ $client->id }}" required>
                                </td>
                                <td class="px-5 py-3">
                                    <input class="size-4 rounded border-slate-300 text-[#0d9488]" name="duplicate_client_ids[]" type="checkbox" value="{{ $client->id }}">
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-950">{{ $client->first_name }} {{ $client->last_name }}</p>
                                    <p class="text-xs text-slate-500">#{{ $client->id }} · {{ $client->phone ?: 'Sin celular' }}{{ $client->email ? ' · '.$client->email : '' }}</p>
                                </td>
                                <td class="px-5 py-3">{{ $client->operator?->name ?? 'Sin operador' }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ $client->loans_count }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ $client->loan_applications_count }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ $client->vehicles_count }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ $client->documents_count }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-5 py-6 text-slate-500" colspan="8">Busca un cliente por nombre, celular o correo para seleccionar duplicados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <p class="text-sm text-slate-500">Tip: busca el nombre repetido, marca uno como principal y los demas como duplicados.</p>
            <button class="rounded-md bg-slate-950 px-5 py-2 text-sm font-bold text-white" type="button" data-open-modal="merge-clients-modal">Unificar seleccionados</button>
        </div>

        <div class="mt-4">{{ $clients->links() }}</div>

        <dialog id="merge-clients-modal" class="w-[min(92vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Unificar clientes</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Confirmar unificacion</h3>
            </div>
            <div class="space-y-2 px-5 py-4 text-sm text-slate-600">
                <p>Esta accion movera todos los registros relacionados al cliente principal.</p>
                <p>Los clientes duplicados no se eliminan; quedan marcados como fusionados para conservar historial.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Si, unificar</button>
            </div>
        </dialog>
    </form>
</x-layouts.app>
