@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $pendingBalanceCents = max(0, -Money::cents($cut->accumulated_balance));
    $previousPendingCents = max(0, -Money::cents($cut->previous_balance));
    $pendingDeliveryCents = max(0, Money::cents($cut->reported_total) - Money::cents($cut->received_total));
    $adjustmentEntries = $cut->ledgerEntries->filter(fn ($entry) => in_array($entry->type, ['regularization', 'overage', 'adjustment_in'], true));
    $adjustmentExits = $cut->ledgerEntries->filter(fn ($entry) => in_array($entry->type, ['shortfall', 'adjustment_out'], true));
@endphp

<x-layouts.app title="Corte {{ $cut->operator->name }} · {{ $cut->period_starts_on->format('d/m') }} - {{ $cut->period_ends_on->format('d/m/Y') }}">
    <div class="no-print mb-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
        @can('loans.formalize')
            <a class="rounded-md border border-slate-300 bg-white px-4 py-2 text-center text-sm font-bold text-slate-700" href="{{ route('loans.create', ['operator_id' => $cut->operator_id, 'weekly_cut_id' => $cut->id]) }}">Registrar prestamo nuevo</a>
        @endcan
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" onclick="window.print()">Imprimir corte</button>
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section class="print-sheet min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Corte semanal Orvix Prestamos</p>
                <div class="mt-2 grid gap-3 md:grid-cols-4">
                    <div>
                        <p class="text-sm text-slate-500">Operador</p>
                        <p class="font-bold text-slate-950">{{ $cut->operator->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Periodo viernes-jueves</p>
                        <p class="font-bold text-slate-950">{{ $cut->period_starts_on->format('d/m/Y') }} - {{ $cut->period_ends_on->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Fecha de conciliacion</p>
                        <p class="font-bold text-slate-950">{{ $cut->settlement_on?->format('d/m/Y') ?? $cut->period_ends_on->addDay()->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Estado</p>
                        <p class="font-bold text-slate-950">{{ StatusLabels::cut($cut->status) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Apertura</p>
                        <p class="font-bold text-slate-950">{{ $cut->submitted_at?->format('d/m/Y H:i') ?? $cut->created_at->format('d/m/Y H:i') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Confirmado por</p>
                        <p class="font-bold text-slate-950">{{ $cut->confirmedBy?->name ?? '-' }}</p>
                    </div>
                </div>
            </div>

            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Cobros reales del corte</h3>
                <p class="mt-1 text-sm text-slate-500">Cobros registrados en sistema durante este periodo. La fecha declarada no mueve el cobro de corte.</p>
            </div>
            <div class="overflow-hidden">
                <table class="cut-print-table w-full table-fixed text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="w-[28%] px-5 py-3">Cliente</th>
                            <th class="w-[24%] px-5 py-3">Credito</th>
                            <th class="w-[18%] px-5 py-3">Fechas</th>
                            <th class="w-[16%] px-5 py-3 text-right">Importes</th>
                            <th class="w-[14%] px-5 py-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($cut->items as $item)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <a class="break-words font-semibold leading-5 text-[#0f766e]" href="{{ route('loans.show', $item->movement->loan) }}">{{ $item->movement->loan->client->first_name }} {{ $item->movement->loan->client->last_name }}</a>
                                    <p class="mt-1 break-all text-xs text-slate-500">{{ $item->movement->folio }}</p>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <p class="font-semibold text-slate-950">{{ $item->movement->loan->vehicle?->model ?? 'Vehiculo' }}</p>
                                    @if ($item->movement->targetInstallment)
                                        <p class="mt-1 text-xs text-slate-500">Letra {{ $item->movement->targetInstallment->number }} · vence {{ $item->movement->targetInstallment->due_date->format('d/m/Y') }}</p>
                                    @else
                                        <p class="mt-1 text-xs text-slate-500">Movimiento general</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <p class="text-xs text-slate-500">Declarada</p>
                                    <p class="font-semibold text-slate-950">{{ $item->movement->operated_on->format('d/m/Y') }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Registro</p>
                                    <p class="font-semibold text-slate-950">{{ ($item->movement->registered_at ?? $item->movement->created_at)->format('d/m/Y H:i') }}</p>
                                </td>
                                <td class="px-5 py-4 text-right align-top">
                                    <p class="text-xs text-slate-500">Pagaré</p>
                                    <p class="font-semibold">{{ Money::mxn($item->reported_amount) }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Recargos/otros</p>
                                    <p>{{ Money::mxn(Money::decimal(Money::cents($item->movement->operator_surcharge_amount) + Money::cents($item->movement->external_concepts_amount))) }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Total</p>
                                    <p class="font-bold text-slate-950">{{ Money::mxn(Money::decimal(Money::cents($item->reported_amount) + Money::cents($item->movement->operator_surcharge_amount) + Money::cents($item->movement->external_concepts_amount))) }}</p>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">{{ StatusLabels::movement($item->movement->confirmation_status) }}</span>
                                    <p class="mt-2 text-xs text-slate-500">Registró</p>
                                    <p class="font-semibold text-slate-950">{{ $item->movement->registeredBy?->name ?? '-' }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="5">No hay cobros registrados en este corte.</td>
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
                    <table class="cut-print-table w-full table-fixed text-left text-sm">
                        <thead class="bg-red-50 text-xs uppercase text-red-700">
                            <tr>
                                <th class="w-[30%] px-5 py-3">Cliente</th>
                                <th class="w-[20%] px-5 py-3">Vehiculo</th>
                                <th class="w-[12%] px-5 py-3">Letra</th>
                                <th class="w-[14%] px-5 py-3">Vencio</th>
                                <th class="w-[14%] px-5 py-3 text-right">Saldo</th>
                                @can('weekly-cuts.confirm')
                                    <th class="w-[10%] px-5 py-3 text-right">Accion</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($overdueInstallments as $installment)
                                <tr>
                                    <td class="px-5 py-3">
                                        <a class="break-words font-semibold text-[#0f766e]" href="{{ route('loans.show', $installment->loan) }}">{{ $installment->loan->client->first_name }} {{ $installment->loan->client->last_name }}</a>
                                        <p class="break-all text-xs text-slate-500">{{ $installment->loan->folio }}</p>
                                    </td>
                                    <td class="px-5 py-3">{{ $installment->loan->vehicle?->model }}</td>
                                    <td class="px-5 py-3">{{ $installment->number }}</td>
                                    <td class="px-5 py-3">{{ $installment->due_date->format('d/m/Y') }}</td>
                                    <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($installment->remaining_amount) }}</td>
                                    @can('weekly-cuts.confirm')
                                        <td class="px-5 py-3 text-right">
                                            <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid>
                                                @csrf
                                                <input name="return_to" type="hidden" value="cut">
                                                <input name="cut_id" type="hidden" value="{{ $cut->id }}">
                                                <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                                <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                                <input name="operator_surcharge_amount" type="hidden" value="0">
                                                <input name="external_concepts_amount" type="hidden" value="0">
                                                <input name="notes" type="hidden" value="Marcado pagado desde atrasados del corte">
                                                <button class="rounded-md bg-[#0d9488] px-3 py-1.5 text-xs font-bold text-white" type="submit">Pagado</button>
                                            </form>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="border-y border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Carteras nuevas</h3>
                <p class="mt-1 text-sm text-slate-500">Fondos entregados al operador para abrir prestamos relacionados con este corte.</p>
            </div>
            <div class="overflow-hidden">
                <table class="cut-print-table w-full table-fixed text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="w-[32%] px-5 py-3">Cliente / prestamo</th>
                            <th class="w-[22%] px-5 py-3">Vehiculo</th>
                            <th class="w-[18%] px-5 py-3 text-right">Importe</th>
                            <th class="w-[14%] px-5 py-3">Entrega</th>
                            <th class="w-[14%] px-5 py-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($cut->fundDisbursements as $disbursement)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="break-words font-semibold text-[#0f766e]">{{ $disbursement->loan->client->first_name }} {{ $disbursement->loan->client->last_name }}</p>
                                    <a class="mt-1 block break-all text-xs font-semibold text-slate-500" href="{{ route('loans.show', $disbursement->loan) }}">{{ $disbursement->loan->folio }}</a>
                                </td>
                                <td class="px-5 py-3">{{ $disbursement->loan->vehicle?->model }} {{ $disbursement->loan->vehicle?->year }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($disbursement->amount) }}</td>
                                <td class="px-5 py-3">
                                    <p class="font-semibold">{{ $disbursement->delivered_on->format('d/m/Y') }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', (string) $disbursement->capital_source)) }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-semibold">{{ StatusLabels::disbursement($disbursement->status) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $disbursement->registeredBy?->name ?? '-' }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="5">No hay fondos entregados para prestamos nuevos en este corte.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="no-print space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Resumen</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Esperado semanal</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->expected_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Cobros reportados</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->reported_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Cobros confirmados/recibidos</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->confirmed_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Pendiente de entregar</dt>
                        <dd class="font-bold text-red-700">{{ Money::mxn(Money::decimal($pendingDeliveryCents)) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Cantidad de cobros</dt>
                        <dd class="font-bold">{{ $cut->items->count() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Fondos carteras nuevas</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->funds_delivered_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Prestamos nuevos</dt>
                        <dd class="font-bold">{{ $cut->fundDisbursements->count() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ajustes entrada</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->adjustments_in_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ajustes salida</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->adjustments_out_total) }}</dd>
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
                        <dt class="text-slate-500">Resultado neto</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->net_result_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Diferencia / descuadre</dt>
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
                @if ($cut->status !== 'closed')
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">{{ $cut->confirmed_at ? 'Actualizar recepción' : 'Confirmar recepción' }}</h3>
                        <p class="mt-1 text-sm text-slate-500">El corte puede seguir recibiendo cobros, prestamos nuevos y ajustes hasta que se cierre.</p>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('cuts.confirm', $cut) }}">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="received_total">Efectivo recibido</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="received_total" name="received_total" type="number" step="0.01" value="{{ Money::cents($cut->received_total) > 0 ? $cut->received_total : $cut->reported_total }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="reason">Nota</label>
                                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="reason" name="reason" rows="2"></textarea>
                            </div>
                            <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="submit">{{ $cut->confirmed_at ? 'Actualizar corte' : 'Confirmar corte' }}</button>
                        </form>
                    </section>
                @endif
                @if ($pendingBalanceCents > 0 && $cut->status !== 'closed')
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
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-bold text-slate-950">{{ $cut->status === 'closed' ? 'Reabrir corte' : 'Cerrar corte' }}</h3>
                    @if ($cut->status === 'closed')
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('cuts.reopen', $cut) }}">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="reopen_reason">Motivo</label>
                                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="reopen_reason" name="reason" rows="2" required></textarea>
                            </div>
                            <button class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" type="submit">Reabrir corte</button>
                        </form>
                    @else
                        <p class="mt-1 text-sm text-slate-500">Bloquea ediciones directas y conserva el historial de conciliacion.</p>
                        <form class="mt-4" method="POST" action="{{ route('cuts.close', $cut) }}">
                            @csrf
                            <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="submit">Cerrar corte</button>
                        </form>
                    @endif
                </section>
            @endcan
        </aside>
    </div>
</x-layouts.app>
