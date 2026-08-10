@php
    use App\Support\Money;
    use App\Support\StatusLabels;
@endphp

<x-layouts.app title="Cortes semanales">
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
            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Generar corte de esta semana</button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-5 py-3">Operador</th>
                    <th class="px-5 py-3">Periodo</th>
                    <th class="px-5 py-3 text-right">Reportado</th>
                    <th class="px-5 py-3 text-right">Recibido</th>
                    <th class="px-5 py-3 text-right">Diferencia</th>
                    <th class="px-5 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($cuts as $cut)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3">
                            <a class="font-semibold text-[#0f766e]" href="{{ route('cuts.show', $cut) }}">{{ $cut->operator->name }}</a>
                        </td>
                        <td class="px-5 py-3">{{ $cut->period_starts_on->format('d/m') }} - {{ $cut->period_ends_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 text-right">{{ Money::mxn($cut->reported_total) }}</td>
                        <td class="px-5 py-3 text-right">{{ Money::mxn($cut->received_total) }}</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($cut->difference_total) }}</td>
                        <td class="px-5 py-3">{{ StatusLabels::cut($cut->status) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $cuts->links() }}</div>
</x-layouts.app>
