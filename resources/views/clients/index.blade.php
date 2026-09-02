<x-layouts.app title="Clientes">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <form class="grid w-full gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[minmax(220px,1.5fr)_minmax(170px,1fr)_minmax(150px,auto)_minmax(150px,auto)_minmax(150px,auto)_auto]" method="GET" action="{{ route('clients.index') }}">
            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="q" placeholder="Buscar cliente, telefono o correo" value="{{ request('q') }}">
            @unless(auth()->user()->hasRole('operador-cartera'))
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" aria-label="Filtrar por operador">
                    <option value="">Todos los operadores</option>
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->id }}" @selected((string) ($clientFilters['operator_id'] ?? '') === (string) $operator->id)>{{ $operator->name }}</option>
                    @endforeach
                </select>
            @endunless
            <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="client_status" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <option value="active" @selected(($clientFilters['client_status'] ?? '') === 'active')>Activo</option>
                <option value="inactive" @selected(($clientFilters['client_status'] ?? '') === 'inactive')>No activo</option>
            </select>
            <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="sort" aria-label="Ordenar clientes">
                <option value="name" @selected(($clientFilters['sort'] ?? 'name') === 'name')>Nombre</option>
                <option value="loans_count" @selected(($clientFilters['sort'] ?? '') === 'loans_count')>Cantidad de creditos</option>
                <option value="active_loans_count" @selected(($clientFilters['sort'] ?? '') === 'active_loans_count')>Creditos activos</option>
            </select>
            <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="direction" aria-label="Direccion del orden">
                <option value="asc" @selected(($clientFilters['direction'] ?? 'asc') === 'asc')>Ascendente</option>
                <option value="desc" @selected(($clientFilters['direction'] ?? '') === 'desc')>Descendente</option>
            </select>
            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Filtrar</button>
        </form>
        @canany(['clients.create', 'clients.manage'])
            <a class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" href="{{ route('clients.create') }}">Agregar cliente</a>
        @endcanany
    </div>

    @include('partials.kpi-cards', ['kpis' => $kpis, 'gridClass' => 'grid-cols-2 md:grid-cols-4 xl:grid-cols-4'])

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            @include('partials.table-pagination', ['paginator' => $clients])
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[980px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-5 py-3">Cliente</th>
                    <th class="px-5 py-3">Operador</th>
                    <th class="px-5 py-3">Creditos</th>
                    <th class="px-5 py-3">Activos</th>
                    <th class="px-5 py-3">Congelados</th>
                    <th class="px-5 py-3">Concluidos</th>
                    <th class="px-5 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($clients as $client)
                    <tr>
                        <td class="px-5 py-3">
                            <a class="font-semibold text-[#0f766e]" href="{{ route('clients.show', $client) }}">{{ $client->first_name }} {{ $client->last_name }}</a>
                            <p class="text-xs text-slate-500">{{ $client->phone }} {{ $client->email ? '· '.$client->email : '' }}</p>
                        </td>
                        <td class="px-5 py-3">{{ $client->operator?->name ?? 'Sin operador' }}</td>
                        <td class="px-5 py-3">{{ $client->loans_count }}</td>
                        <td class="px-5 py-3">{{ $client->active_loans_count }}</td>
                        <td class="px-5 py-3">{{ $client->frozen_loans_count }}</td>
                        <td class="px-5 py-3">{{ $client->concluded_loans_count }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded px-2 py-1 text-xs font-bold {{ $client->active_loans_count + $client->frozen_loans_count > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $client->active_loans_count + $client->frozen_loans_count > 0 ? 'Activo' : 'No activo' }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-500" colspan="7">No hay clientes para mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </section>

    <div class="mt-4">{{ $clients->links() }}</div>
</x-layouts.app>
