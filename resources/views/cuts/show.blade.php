@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $pendingBalanceCents = max(0, -Money::cents($cut->accumulated_balance));
    $previousPendingCents = max(0, -Money::cents($cut->previous_balance));
@endphp

<x-layouts.app title="Corte {{ $cut->operator->name }} · {{ $cut->period_starts_on->format('d/m') }} - {{ $cut->period_ends_on->format('d/m/Y') }}">
    <div class="no-print mb-4 flex justify-end">
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" onclick="window.print()">Imprimir corte</button>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <section class="print-sheet rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Corte semanal Orvix Prestamos</p>
                <div class="mt-2 grid gap-3 md:grid-cols-4">
                    <div>
                        <p class="text-sm text-slate-500">Operador</p>
                        <p class="font-bold text-slate-950">{{ $cut->operator->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Semana</p>
                        <p class="font-bold text-slate-950">{{ $cut->period_starts_on->format('d/m/Y') }} - {{ $cut->period_ends_on->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Estado</p>
                        <p class="font-bold text-slate-950">{{ StatusLabels::cut($cut->status) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Generado</p>
                        <p class="font-bold text-slate-950">{{ now('America/Merida')->format('d/m/Y H:i') }}</p>
                    </div>
                </div>
            </div>

            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Pagos marcados en cobranza</h3>
                <p class="mt-1 text-sm text-slate-500">Estos son los prestamos/letras marcados como pagados durante la semana del corte.</p>
            </div>
            <div class="overflow-hidden">
                <table class="cut-print-table w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            @can('weekly-cuts.confirm')
                                <th class="check-column px-5 py-3 text-center">Check</th>
                            @endcan
                            <th class="px-5 py-3">Cliente</th>
                            <th class="px-5 py-3">Vehiculo</th>
                            <th class="px-5 py-3">Letra</th>
                            <th class="px-5 py-3">Marcado</th>
                            <th class="px-5 py-3 text-right">Pagaré</th>
                            <th class="px-5 py-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($cut->items as $item)
                            <tr>
                                @can('weekly-cuts.confirm')
                                    <td class="check-column px-5 py-3 text-center">
                                        <span class="inline-block size-4 rounded-sm border border-slate-400 align-middle"></span>
                                    </td>
                                @endcan
                                <td class="px-5 py-3">
                                    <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $item->movement->loan) }}">{{ $item->movement->loan->client->first_name }} {{ $item->movement->loan->client->last_name }}</a>
                                    <p class="text-xs text-slate-500">{{ $item->movement->folio }}</p>
                                </td>
                                <td class="px-5 py-3">{{ $item->movement->loan->vehicle?->model }}</td>
                                <td class="px-5 py-3">
                                    @if ($item->movement->targetInstallment)
                                        {{ $item->movement->targetInstallment->number }} · vence {{ $item->movement->targetInstallment->due_date->format('d/m/Y') }}
                                    @else
                                        Movimiento general
                                    @endif
                                </td>
                                <td class="px-5 py-3">{{ $item->movement->operated_on->format('d/m/Y') }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($item->reported_amount) }}</td>
                                <td class="px-5 py-3">{{ StatusLabels::movement($item->movement->confirmation_status) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="{{ auth()->user()->can('weekly-cuts.confirm') ? 7 : 6 }}">No hay pagos marcados esta semana.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($overdueInstallments->isNotEmpty())
                <div class="border-y border-slate-200 px-5 py-4">
                    <h3 class="font-bold text-slate-950">Atrasados sin marcar</h3>
                    <p class="mt-1 text-sm text-slate-500">Letras de semanas anteriores que no fueron marcadas como pagadas; se arrastran al corte siguiente.</p>
                </div>
                <div class="overflow-hidden">
                    <table class="cut-print-table w-full text-left text-sm">
                        <thead class="bg-red-50 text-xs uppercase text-red-700">
                            <tr>
                                @can('weekly-cuts.confirm')
                                    <th class="check-column px-5 py-3 text-center">Check</th>
                                @endcan
                                <th class="px-5 py-3">Cliente</th>
                                <th class="px-5 py-3">Vehiculo</th>
                                <th class="px-5 py-3">Letra</th>
                                <th class="px-5 py-3">Vencio</th>
                                <th class="px-5 py-3 text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($overdueInstallments as $installment)
                                <tr>
                                    @can('weekly-cuts.confirm')
                                        <td class="check-column px-5 py-3 text-center">
                                            <span class="inline-block size-4 rounded-sm border border-slate-400 align-middle"></span>
                                        </td>
                                    @endcan
                                    <td class="px-5 py-3">
                                        <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $installment->loan) }}">{{ $installment->loan->client->first_name }} {{ $installment->loan->client->last_name }}</a>
                                        <p class="text-xs text-slate-500">{{ $installment->loan->folio }}</p>
                                    </td>
                                    <td class="px-5 py-3">{{ $installment->loan->vehicle?->model }}</td>
                                    <td class="px-5 py-3">{{ $installment->number }}</td>
                                    <td class="px-5 py-3">{{ $installment->due_date->format('d/m/Y') }}</td>
                                    <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($installment->remaining_amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <aside class="no-print space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Resumen</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">A entregar</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->expected_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Marcado en cobranza</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->reported_total) }}</dd>
                    </div>
                    @if ($previousPendingCents > 0)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Pendiente anterior</dt>
                            <dd class="font-bold text-red-700">{{ Money::mxn(Money::decimal($previousPendingCents)) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Recibido por administracion</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->received_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Diferencia de recepcion</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->difference_total) }}</dd>
                    </div>
                    @if (Money::cents($cut->regularization_total) > 0)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Liquidado despues</dt>
                            <dd class="font-bold text-emerald-700">{{ Money::mxn($cut->regularization_total) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-slate-700">{{ $pendingBalanceCents > 0 ? 'Saldo pendiente vivo' : 'Saldo pendiente' }}</dt>
                        <dd class="font-bold {{ $pendingBalanceCents > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ Money::mxn(Money::decimal($pendingBalanceCents)) }}</dd>
                    </div>
                    @if ($cut->balance_settled_at)
                        <div class="rounded-md bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                            Liquidado el {{ $cut->balance_settled_at->format('d/m/Y') }}.
                        </div>
                    @endif
                </dl>
            </section>

            @can('weekly-cuts.confirm')
                @if (! in_array($cut->status, ['confirmed', 'with_difference'], true))
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Confirmar recepción</h3>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('cuts.confirm', $cut) }}">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="received_total">Efectivo recibido</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="received_total" name="received_total" type="number" step="0.01" value="{{ $cut->reported_total }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="reason">Nota</label>
                                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="reason" name="reason" rows="2"></textarea>
                            </div>
                            <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="submit">Confirmar corte</button>
                        </form>
                    </section>
                @endif
                @if ($pendingBalanceCents > 0 && in_array($cut->status, ['confirmed', 'with_difference'], true))
                    <section class="rounded-lg border border-red-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Liquidar saldo pendiente</h3>
                        <p class="mt-1 text-sm text-slate-500">Este pago regulariza el faltante de este corte y actualiza el saldo que se arrastra.</p>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('cuts.settle-balance', $cut) }}">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="amount">Monto a liquidar</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="number" step="0.01" max="{{ Money::decimal($pendingBalanceCents) }}" value="{{ Money::decimal($pendingBalanceCents) }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="settled_on">Fecha de liquidacion</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="settled_on" name="settled_on" type="date" value="{{ now('America/Merida')->toDateString() }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="settlement_reason">Nota</label>
                                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="settlement_reason" name="reason" rows="2"></textarea>
                            </div>
                            <button class="w-full rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white" type="submit">Liquidar saldo</button>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
</x-layouts.app>
