@php use App\Support\StatusLabels; @endphp

<x-layouts.app title="Clientes">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <form class="flex gap-2" method="GET" action="{{ route('clients.index') }}">
            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:w-80" name="q" placeholder="Buscar cliente, telefono o correo" value="{{ request('q') }}">
            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Buscar</button>
        </form>
        @canany(['clients.create', 'clients.manage'])
            <a class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" href="{{ route('clients.create') }}">Agregar cliente</a>
        @endcanany
    </div>

    @include('partials.kpi-cards', ['kpis' => $kpis])

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-5 py-3">Cliente</th>
                    <th class="px-5 py-3">Operador</th>
                    <th class="px-5 py-3">Creditos</th>
                    <th class="px-5 py-3">Activos</th>
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
                        <td class="px-5 py-3">{{ StatusLabels::client($client->status) }}</td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-500" colspan="5">No hay clientes para mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $clients->links() }}</div>
</x-layouts.app>
