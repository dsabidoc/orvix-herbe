@php
    use App\Support\Money;
    use App\Support\StatusLabels;
@endphp

<x-layouts.app title="Cortes">
    <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <form class="flex flex-col gap-3 md:flex-row md:items-end" method="POST" action="{{ route('cuts.store') }}">
            @csrf
            @unless (auth()->user()->hasRole('operador-cartera'))
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="operator_id">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operator_id" name="operator_id">
                        <option value="">Todos</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div>
                <label class="text-sm font-semibold text-slate-700" for="cut_date">Fecha de corte</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="cut_date" name="cut_date" type="date" value="{{ now('America/Merida')->toDateString() }}" required>
            </div>
            <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="submit">Generar corte</button>
        </form>
    </div>

    <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <form class="flex flex-col gap-3 md:flex-row md:items-end" method="GET" action="{{ route('cuts.index') }}">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="summary_month">Mes de pago o corte</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="summary_month" name="month" type="month" value="{{ request('month') }}">
            </div>
            @unless (auth()->user()->hasRole('operador-cartera'))
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-semibold text-slate-700" for="summary_operator_id">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="summary_operator_id" name="operator_id">
                        <option value="">Todos los operadores</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected($selectedOperatorId === $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Filtrar</button>
            <a class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-bold text-slate-700" href="{{ route('cuts.index') }}">Total</a>
        </form>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['title' => 'Cortes', 'value' => number_format($summary['cuts']), 'caption' => 'Incluidos en el filtro', 'color' => 'blue'],
            ['title' => 'Total reportado', 'value' => Money::mxn(Money::decimal($summary['reported_cents'])), 'caption' => 'Cobros registrados en cortes', 'color' => 'yellow'],
            ['title' => 'Total cobrado', 'value' => Money::mxn(Money::decimal($summary['received_cents'])), 'caption' => 'Cortes concluidos por fecha de pago', 'color' => 'green'],
            ['title' => 'Operadores', 'value' => number_format($summary['operators']), 'caption' => 'Con cortes en el filtro', 'color' => 'orange'],
        ] as $card)
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-500">{{ $card['title'] }}</p>
                <p class="mt-2 text-2xl font-black text-slate-950">{{ $card['value'] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $card['caption'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            @include('partials.table-pagination', ['paginator' => $cuts])
        </div>
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-5 py-3">Operador</th>
                    <th class="px-5 py-3">Fecha de corte</th>
                    <th class="px-5 py-3">Fecha de pago</th>
                    <th class="px-5 py-3">Generado</th>
                    <th class="px-5 py-3 text-right">Reportado</th>
                    <th class="px-5 py-3 text-right">Recibido</th>
                    <th class="px-5 py-3">Estado</th>
                    @can('weekly-cuts.confirm')
                        <th class="px-5 py-3 text-right">Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($cuts as $cut)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3">
                            <a class="font-semibold text-[#0f766e]" href="{{ route('cuts.show', $cut) }}">{{ $cut->operator->name }}</a>
                        </td>
                        <td class="px-5 py-3">{{ $cut->period_starts_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3">{{ $cut->confirmed_at?->format('d/m/Y') ?? '-' }}</td>
                        <td class="px-5 py-3">{{ ($cut->submitted_at ?? $cut->created_at)->format('d/m/Y H:i') }}</td>
                        <td class="px-5 py-3 text-right">{{ Money::mxn($cut->reported_total) }}</td>
                        <td class="px-5 py-3 text-right">{{ Money::mxn($cut->received_total) }}</td>
                        <td class="px-5 py-3">{{ StatusLabels::cut($cut->status) }}</td>
                        @can('weekly-cuts.confirm')
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('cuts.destroy', $cut) }}" data-confirm-delete data-confirm-title="¿Eliminar este corte?" data-confirm-message="Se eliminara solo este corte. Los cobros, prestamos nuevos y movimientos relacionados se conservaran sin corte asignado.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700" type="submit">Eliminar</button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $cuts->links() }}</div>
</x-layouts.app>
