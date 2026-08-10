@php
    use App\Support\StatusLabels;

    $statusClasses = [
        'submitted' => 'bg-blue-50 text-blue-700',
        'approved' => 'bg-emerald-50 text-emerald-700',
        'started' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-red-50 text-red-700',
        'draft' => 'bg-slate-100 text-slate-700',
    ];
@endphp

<x-layouts.app title="Solicitudes">
    <div class="mb-4 flex justify-end">
        @canany(['applications.create', 'applications.authorize'])
            <a class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" href="{{ route('applications.create') }}">Nueva solicitud</a>
        @endcanany
    </div>

    <form class="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm" method="GET" action="{{ route('applications.index') }}">
        <div class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="q">Buscar folio, cliente, telefono u operador</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" type="search" value="{{ request('q') }}" placeholder="SOL-26-0001, Esteban, Samuel...">
            </div>
            <button class="rounded-md bg-[#0d9488] px-5 py-2 text-sm font-bold text-white" type="submit">Buscar</button>
        </div>
    </form>

    @include('partials.kpi-cards', ['kpis' => $kpis])

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr><th class="px-5 py-3">Folio</th><th class="px-5 py-3">Cliente</th><th class="px-5 py-3">Operador</th><th class="px-5 py-3 text-right">Monto</th><th class="px-5 py-3">Estado</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($applications as $application)
                    <tr>
                        <td class="px-5 py-3 font-semibold text-slate-700">{{ $application->folio }}</td>
                        <td class="px-5 py-3"><a class="font-semibold text-[#0f766e]" href="{{ route('applications.show', $application) }}">{{ $application->client->first_name }} {{ $application->client->last_name }}</a></td>
                        <td class="px-5 py-3">{{ $application->operator?->name }}</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ \App\Support\Money::mxn($application->requested_capital) }}</td>
                        <td class="px-5 py-3">
                            <span class="{{ $statusClasses[$application->status] ?? 'bg-slate-100 text-slate-700' }} rounded px-2 py-1 text-xs font-bold">{{ StatusLabels::application($application->status) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-500" colspan="5">No hay solicitudes.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t border-slate-200 px-5 py-4">
            {{ $applications->links() }}
        </div>
    </section>
</x-layouts.app>
