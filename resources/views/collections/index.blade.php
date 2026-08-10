@php
    use App\Support\Money;

    $today = now('America/Merida')->toDateString();
@endphp

<x-layouts.app title="Cobranza mensual">
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <form class="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:flex-row md:items-end" method="GET">
        <div>
            <label class="text-sm font-semibold text-slate-700" for="month">Mes</label>
            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="month" name="month" type="month" value="{{ $month->format('Y-m') }}">
        </div>
        @unless (auth()->user()->hasRole('operador-cartera'))
            <div>
                <label class="text-sm font-semibold text-slate-700" for="operator_id">Operador</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operator_id" name="operator_id">
                    <option value="">Todos</option>
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->id }}" @selected($selectedOperatorId === $operator->id)>{{ $operator->name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Consultar</button>
    </form>

    @include('partials.kpi-cards', ['kpis' => $kpis])

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Letras de {{ $month->translatedFormat('F Y') }}</h3>
            <p class="mt-1 text-sm text-slate-500">Incluye atrasadas anteriores sin marcar para que se arrastren al siguiente corte.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[980px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Vence</th>
                        <th class="px-5 py-3">Cliente</th>
                        <th class="px-5 py-3">Vehiculo</th>
                        <th class="px-5 py-3">Operador</th>
                        <th class="px-5 py-3 text-right">Letra</th>
                        <th class="px-5 py-3">Estado</th>
                        <th class="px-5 py-3 text-right">Accion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($installments as $installment)
                        @php
                            $movement = $installment->reportedMovement;
                            $isCovered = Money::cents($installment->remaining_amount) <= 0;
                            $isOverdue = ! $isCovered && ! $movement && $installment->due_date->toDateString() < $today;
                            $badgeClass = $isCovered
                                ? 'bg-emerald-50 text-emerald-700'
                                : ($movement ? 'bg-amber-50 text-amber-700' : ($isOverdue ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-700'));
                            $badge = $isCovered ? 'pagada' : ($movement ? 'por confirmar' : ($isOverdue ? 'atrasada' : 'pendiente'));
                        @endphp
                        <tr class="{{ $isOverdue ? 'bg-red-50/30' : '' }}">
                            <td class="px-5 py-3 font-semibold">{{ $installment->due_date->format('d/m/Y') }}</td>
                            <td class="px-5 py-3">
                                <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $installment->loan) }}">{{ $installment->loan->client->first_name }} {{ $installment->loan->client->last_name }}</a>
                                <p class="text-xs text-slate-500">{{ $installment->loan->folio }} · letra {{ $installment->number }}</p>
                            </td>
                            <td class="px-5 py-3">{{ $installment->loan->vehicle?->model }} {{ $installment->loan->vehicle?->year }}</td>
                            <td class="px-5 py-3">{{ $installment->loan->operator?->name }}</td>
                            <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($installment->remaining_amount) }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded px-2 py-1 text-xs font-bold uppercase {{ $badgeClass }}">{{ $badge }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if (! $isCovered && ! $movement)
                                    <form class="inline-flex items-center gap-2" method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid>
                                        @csrf
                                        <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                        <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                        <input name="operator_surcharge_amount" type="hidden" value="0">
                                        <input name="external_concepts_amount" type="hidden" value="0">
                                        <button class="rounded-md bg-[#0d9488] px-3 py-2 text-xs font-bold text-white" type="submit">Pagado</button>
                                    </form>
                                @else
                                    <span class="text-xs font-semibold text-slate-400">Sin accion</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-6 text-sm text-slate-500" colspan="7">No hay letras en este periodo.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $installments->links() }}</div>
</x-layouts.app>
