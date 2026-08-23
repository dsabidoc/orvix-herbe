@php
    use App\Support\Money;
    use App\Support\StatusLabels;
@endphp

<x-layouts.app title="Carteras liquidadas">
    <form class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[minmax(0,1fr)_auto] md:items-end" method="GET">
        <div>
            <label class="text-sm font-semibold text-slate-700" for="q">Buscar cliente, folio, vehiculo, placas o VIN</label>
            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" value="{{ request('q') }}">
        </div>
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Filtrar</button>
    </form>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-bold text-slate-950">Liquidados</h2>
                <p class="mt-1 text-sm text-slate-500">Carteras liquidadas por boton de liquidacion o por calendario pagado completo.</p>
            </div>
            @include('partials.table-pagination', ['paginator' => $loans])
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Prestamo</th>
                        <th class="px-5 py-3">Vehiculo</th>
                        <th class="px-5 py-3">Operador</th>
                        <th class="px-5 py-3">Liquidacion</th>
                        <th class="px-5 py-3 text-right">Capital</th>
                        <th class="px-5 py-3 text-right">Aplicado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($loans as $loan)
                        @php
                            $applied = $loan->installments->sum(fn ($installment) => Money::cents($installment->applied_amount));
                            $liquidatedAt = $loan->settled_at ?? ($loan->latest_applied_at ? \Carbon\CarbonImmutable::parse($loan->latest_applied_at, 'America/Merida') : $loan->updated_at);
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $loan) }}">{{ $loan->folio }}</a>
                                <p class="text-xs text-slate-500">{{ $loan->client?->first_name }} {{ $loan->client?->last_name }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p class="font-semibold">{{ $loan->vehicle?->model ?: 'Vehiculo' }} · Dia {{ $loan->payment_day }}</p>
                                <p class="text-xs text-slate-500">{{ $loan->vehicle?->brand }} {{ $loan->vehicle?->year }} · {{ $loan->vehicle?->plates ?: 'Sin placas' }}</p>
                            </td>
                            <td class="px-5 py-3">{{ $loan->operator?->name }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ StatusLabels::loan('settled') }}</span>
                                <p class="mt-1 text-xs text-slate-500">{{ $liquidatedAt?->format('d/m/Y H:i') }}</p>
                                <p class="text-xs text-slate-500">{{ StatusLabels::settlementReason($loan->settlement_reason ?: 'calendario_pagado') }}</p>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($loan->capital) }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn(Money::decimal($applied)) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-6 text-slate-500" colspan="6">No hay carteras liquidadas para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $loans->links() }}</div>
</x-layouts.app>
