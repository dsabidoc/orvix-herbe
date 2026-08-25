@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $pendingDeliveryCents = max(0, Money::cents($cut->reported_total) - Money::cents($cut->received_total));
    $adjustmentEntries = $cut->ledgerEntries->filter(fn ($entry) => in_array($entry->type, ['regularization', 'overage', 'adjustment_in'], true));
    $adjustmentExits = $cut->ledgerEntries->filter(fn ($entry) => in_array($entry->type, ['shortfall', 'adjustment_out'], true));
@endphp

<x-layouts.app title="Corte {{ $cut->operator->name }} · {{ $cut->period_starts_on->format('d/m/Y') }}">
    <div class="no-print mb-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
        @can('loans.formalize')
            <a class="rounded-md border border-slate-300 bg-white px-4 py-2 text-center text-sm font-bold text-slate-700" href="{{ route('loans.create', ['operator_id' => $cut->operator_id, 'weekly_cut_id' => $cut->id]) }}">Registrar prestamo nuevo</a>
        @endcan
        @can('weekly-cuts.confirm')
            @if ($cut->status !== 'closed')
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" type="button" data-open-modal="cut-advance-modal">Adelanto</button>
            @endif
        @endcan
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" onclick="window.print()">Imprimir corte</button>
        @can('weekly-cuts.confirm')
            <form method="POST" action="{{ route('cuts.destroy', $cut) }}" data-confirm-delete data-confirm-title="¿Eliminar este corte?" data-confirm-message="Se eliminara solo este corte. Los cobros, prestamos nuevos y movimientos relacionados se conservaran sin corte asignado.">
                @csrf
                @method('DELETE')
                <button class="w-full rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 sm:w-auto" type="submit">Eliminar corte</button>
            </form>
        @endcan
    </div>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section class="print-sheet min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Corte Orvix Prestamos</p>
                <div class="mt-2 grid gap-3 md:grid-cols-4">
                    <div>
                        <p class="text-sm text-slate-500">Operador</p>
                        <p class="font-bold text-slate-950">{{ $cut->operator->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Fecha de corte</p>
                        <p class="font-bold text-slate-950">{{ $cut->period_starts_on->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">Generado</p>
                        <p class="font-bold text-slate-950">{{ ($cut->submitted_at ?? $cut->created_at)->format('d/m/Y H:i') }}</p>
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

            <div class="print-only cut-print-summary border-b border-slate-200 px-5 py-4">
                <h3 class="cut-print-summary-title">Resumen del corte</h3>
                <dl>
                    <div>
                        <dt>Cobros reportados</dt>
                        <dd>{{ Money::mxn($cut->reported_total) }}</dd>
                    </div>
                    <div>
                        <dt>Cobros confirmados/recibidos</dt>
                        <dd>{{ Money::mxn($cut->confirmed_total) }}</dd>
                    </div>
                    <div>
                        <dt>Pendiente de entregar</dt>
                        <dd>{{ Money::mxn(Money::decimal($pendingDeliveryCents)) }}</dd>
                    </div>
                    <div>
                        <dt>Cantidad de cobros</dt>
                        <dd>{{ $cut->items->count() }}</dd>
                    </div>
                    <div>
                        <dt>Recibir por administrador</dt>
                        <dd>{{ Money::mxn($cut->received_total) }}</dd>
                    </div>
                    <div>
                        <dt>Fecha de pago</dt>
                        <dd>{{ $cut->confirmed_at?->format('d/m/Y') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt>Diferencia</dt>
                        <dd>{{ Money::mxn($cut->difference_total) }}</dd>
                    </div>
                    <div>
                        <dt>Saldo</dt>
                        <dd>{{ Money::mxn(Money::decimal($pendingDeliveryCents)) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="no-print flex flex-wrap gap-2 border-b border-slate-200 px-5 py-3" data-cut-tabs>
                <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" data-cut-tab-button="payments">Cobros del corte</button>
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" type="button" data-cut-tab-button="pending">Atrasados sin marcar</button>
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" type="button" data-cut-tab-button="new-loans">Carteras nuevas</button>
            </div>

            <div id="cut-payments" data-cut-tab-panel="payments">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Cobros del corte</h3>
                <p class="mt-1 text-sm text-slate-500">Cobros pagados vigentes incluidos al momento de generar este corte.</p>
            </div>
            <div class="overflow-hidden">
                <table class="cut-print-table w-full table-fixed text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="w-[24%] px-5 py-3">Cliente</th>
                            <th class="w-[22%] px-5 py-3">Credito</th>
                            <th class="w-[17%] px-5 py-3">Fechas</th>
                            <th class="w-[15%] px-5 py-3 text-right">Importes</th>
                            <th class="w-[12%] px-5 py-3">Estado</th>
                            @can('weekly-cuts.confirm')
                                <th class="no-print w-[10%] px-5 py-3 text-right">Accion</th>
                            @endcan
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
                                    <p class="font-semibold">{{ Money::mxn($item->movement->contract_amount) }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Recargos/otros</p>
                                    <p>{{ Money::mxn(Money::decimal(Money::cents($item->movement->operator_surcharge_amount) + Money::cents($item->movement->external_concepts_amount) + Money::cents($item->movement->additional_charge_amount ?? 0) + Money::cents($item->movement->delinquency_amount ?? 0))) }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Total</p>
                                    <p class="font-bold text-slate-950">{{ Money::mxn($item->reported_amount) }}</p>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">{{ StatusLabels::movement($item->movement->confirmation_status) }}</span>
                                    <p class="mt-2 text-xs text-slate-500">Registró</p>
                                    <p class="font-semibold text-slate-950">{{ $item->movement->registeredBy?->name ?? '-' }}</p>
                                </td>
                                @can('weekly-cuts.confirm')
                                    <td class="no-print px-5 py-4 text-right align-top">
                                        @if ($cut->status !== 'closed')
                                            <form method="POST" action="{{ route('cuts.movements.reverse', [$cut, $item->movement]) }}" data-confirm-delete data-confirm-title="¿Revertir este movimiento?" data-confirm-message="Se quitara del corte y regresara como pendiente si la letra aun tiene saldo.">
                                                @csrf
                                                <button class="rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700" type="submit">Revertir</button>
                                            </form>
                                        @else
                                            <span class="text-xs font-semibold text-slate-400">-</span>
                                        @endif
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="@can('weekly-cuts.confirm') 6 @else 5 @endcan">No hay cobros registrados en este corte.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>

            <div id="cut-pending-installments" class="hidden" data-cut-tab-panel="pending">
            @if ($pendingInstallments->isNotEmpty())
                <div class="no-print border-y border-slate-200 px-5 py-4">
                    <h3 class="font-bold text-slate-950">Atrasados sin marcar</h3>
                    <p class="mt-1 text-sm text-slate-500">Letras vencidas y pendientes hasta la fecha del corte. Se ordenan por dia de pago para registrar el corte desde aqui.</p>
                    <div class="mt-3">
                        <label class="text-sm font-semibold text-slate-700" for="pending_installments_search_{{ $cut->id }}">Buscar</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="pending_installments_search_{{ $cut->id }}" type="search" placeholder="Buscar por modelo, folio, cliente, fecha o pago" data-cut-pending-search="cut-pending-{{ $cut->id }}">
                    </div>
                </div>
                <div class="no-print overflow-x-auto">
                    <table class="cut-print-table w-auto text-left text-sm">
                        <thead class="bg-red-50 text-xs uppercase text-red-700">
                            <tr>
                                <th class="px-3 py-3">Modelo / dia</th>
                                <th class="px-3 py-3">Cliente</th>
                                <th class="whitespace-nowrap px-3 py-3">Num pagare</th>
                                <th class="whitespace-nowrap px-3 py-3">Fecha vencimiento</th>
                                <th class="whitespace-nowrap px-3 py-3 text-right">Pago</th>
                                @can('weekly-cuts.confirm')
                                    <th class="whitespace-nowrap px-3 py-3 text-right">Accion</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($pendingInstallments as $installment)
                                @php
                                    $graceLimit = $installment->due_date->copy()->addDays((int) ($installment->loan->delinquency_grace_days ?? 0))->toDateString();
                                    $delinquencyCents = ((float) ($installment->loan->delinquency_rate ?? 0) > 0 && $graceLimit < $cut->period_starts_on->toDateString())
                                        ? (int) round(Money::cents($installment->contract_amount) * ((float) $installment->loan->delinquency_rate / 100))
                                        : 0;
                                    $vehicleLabel = trim((string) ($installment->loan->vehicle?->model ?? 'Vehiculo'));
                                    $searchText = implode(' ', [
                                        $vehicleLabel,
                                        $installment->loan->payment_day,
                                        $installment->loan->folio,
                                        $installment->number,
                                        $installment->due_date->format('d/m/Y'),
                                        $installment->loan->client->first_name,
                                        $installment->loan->client->last_name,
                                        Money::mxn($installment->remaining_amount),
                                    ]);
                                @endphp
                                <tr data-cut-pending-row="cut-pending-{{ $cut->id }}" data-search-text="{{ $searchText }}">
                                    <td class="px-3 py-3">
                                        <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $installment->loan) }}">{{ $vehicleLabel }} · Dia {{ $installment->loan->payment_day }}</a>
                                        <p class="text-xs text-slate-500">{{ $installment->loan->folio }}</p>
                                    </td>
                                    <td class="px-3 py-3 font-semibold text-slate-950">{{ $installment->loan->client->first_name }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $installment->number }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $installment->due_date->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-semibold">{{ Money::mxn($installment->remaining_amount) }}</td>
                                    @can('weekly-cuts.confirm')
                                        <td class="whitespace-nowrap px-3 py-3 text-right">
                                            <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid>
                                                @csrf
                                                <input name="return_to" type="hidden" value="cut">
                                                <input name="cut_id" type="hidden" value="{{ $cut->id }}">
                                                <input name="operated_on" type="hidden" value="{{ $cut->period_starts_on->toDateString() }}">
                                                <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                                <input name="operator_surcharge_amount" type="hidden" value="0">
                                                <input name="external_concepts_amount" type="hidden" value="0">
                                                <input name="additional_charge_amount" type="hidden" value="0">
                                                <input name="delinquency_amount" type="hidden" value="{{ Money::decimal($delinquencyCents) }}">
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
            @else
                <div class="no-print border-y border-slate-200 px-5 py-6 text-sm text-slate-500">No hay atrasados sin marcar para este corte.</div>
            @endif
            </div>

            <div id="cut-new-loans" class="hidden" data-cut-tab-panel="new-loans">
            <div class="no-print border-y border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Carteras nuevas</h3>
                <p class="mt-1 text-sm text-slate-500">Fondos entregados al operador para abrir prestamos relacionados con este corte.</p>
            </div>
            <div class="no-print overflow-hidden">
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
            </div>
        </section>

        <aside class="no-print space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Resumen</h3>
                <dl class="mt-4 space-y-3 text-sm">
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
                        <dt class="text-slate-500">Recibir por administrador</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->received_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Fecha de pago</dt>
                        <dd class="font-bold">{{ $cut->confirmed_at?->format('d/m/Y') ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Diferencia</dt>
                        <dd class="font-bold">{{ Money::mxn($cut->difference_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-slate-700">Saldo</dt>
                        <dd class="font-bold {{ $pendingDeliveryCents > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ Money::mxn(Money::decimal($pendingDeliveryCents)) }}</dd>
                    </div>
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
                                <label class="text-sm font-semibold text-slate-700" for="received_on">Fecha de pago</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="received_on" name="received_on" type="date" value="{{ $cut->confirmed_at?->toDateString() ?? now('America/Merida')->toDateString() }}" required>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700" for="reason">Nota</label>
                                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="reason" name="reason" rows="2"></textarea>
                            </div>
                            <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="submit">{{ $cut->confirmed_at ? 'Actualizar corte' : 'Confirmar corte' }}</button>
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

    @can('weekly-cuts.confirm')
        @if ($cut->status !== 'closed')
            <dialog id="cut-advance-modal" class="w-[min(96vw,1100px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Adelanto / liquidacion</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $cut->operator->name }} · {{ $cut->period_starts_on->format('d/m/Y') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">Busca cualquier cartera activa del operador de este corte para registrar pagos, adelantar letras o liquidarla sin salir de la pantalla.</p>
                </div>
                <div class="space-y-4 px-5 py-4">
                    @if ($advanceLoans->isEmpty())
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            No hay carteras activas con saldo pendiente para este operador.
                        </div>
                    @else
                        <div>
                            <label class="text-sm font-semibold text-slate-700" for="cut_advance_loan_search">Buscar cartera</label>
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="cut_advance_loan_search" type="search" placeholder="Buscar por modelo, dia, folio o cliente" data-quick-payment-search="cut_advance_loan">
                            <label class="text-sm font-semibold text-slate-700" for="cut_advance_loan">Cartera</label>
                            <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="cut_advance_loan" data-quick-payment-select>
                                <option value="">Seleccionar cartera</option>
                                @foreach ($advanceLoans as $loan)
                                    @php
                                        $advanceSearchText = implode(' ', [
                                            $loan->vehicle?->model ?? 'Vehiculo',
                                            $loan->payment_day,
                                            $loan->folio,
                                            $loan->client->first_name,
                                            $loan->client->last_name,
                                        ]);
                                    @endphp
                                    <option value="cut-advance-loan-{{ $loan->id }}" data-search-text="{{ $advanceSearchText }}">
                                        {{ $loan->vehicle?->model ?? 'Vehiculo' }} · Dia {{ $loan->payment_day }} · {{ $loan->folio }} · {{ $loan->client->first_name }} {{ $loan->client->last_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @foreach ($advanceLoans as $loan)
                            @php
                                $quote = $loan->getAttribute('cut_settlement_quote');
                                $cutDate = $cut->period_starts_on;
                                $cutDateString = $cutDate->toDateString();
                                $cutMonthEndString = $cutDate->copy()->endOfMonth()->toDateString();
                            @endphp
                            <section class="hidden rounded-lg border border-slate-200" data-quick-payment-panel="cut-advance-loan-{{ $loan->id }}" id="cut-advance-loan-{{ $loan->id }}">
                                <div class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ $loan->folio }}</p>
                                        <h4 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->vehicle?->model ?? 'Vehiculo' }} · Dia {{ $loan->payment_day }}</h4>
                                        <p class="text-sm text-slate-500">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
                                    </div>
                                    <form class="rounded-lg border border-red-200 bg-red-50 p-3" method="POST" action="{{ route('loans.settle', $loan) }}">
                                        @csrf
                                        <input name="return_to" type="hidden" value="cut">
                                        <input name="cut_id" type="hidden" value="{{ $cut->id }}">
                                        <input name="settled_on" type="hidden" value="{{ $cutDateString }}">
                                        <div class="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                                            <div>
                                                <label class="text-xs font-semibold text-red-700" for="settlement_reason_{{ $loan->id }}">Liquidar en este corte</label>
                                                <select class="mt-1 w-full rounded-md border border-red-200 bg-white px-3 py-2 text-sm" id="settlement_reason_{{ $loan->id }}" name="settlement_reason" required>
                                                    <option value="pronto_pago_cliente">Pronto pago del cliente</option>
                                                    <option value="dejo_de_pagar">Dejo de pagar; cobrador liquida</option>
                                                </select>
                                                <p class="mt-1 text-xs text-red-700">Monto a liquidar: <strong>{{ Money::mxn(Money::decimal($quote['total_cents'] ?? 0)) }}</strong></p>
                                            </div>
                                            <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white" type="submit">Liquidar</button>
                                        </div>
                                    </form>
                                </div>
                                <div class="max-h-[48vh] overflow-auto">
                                    <table class="w-full min-w-[760px] text-left text-sm">
                                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                            <tr>
                                                <th class="px-4 py-3">Letra</th>
                                                <th class="px-4 py-3">Vence</th>
                                                <th class="px-4 py-3 text-right">Abono capital</th>
                                                <th class="px-4 py-3 text-right">Interes</th>
                                                <th class="px-4 py-3 text-right">Subtotal</th>
                                                <th class="px-4 py-3 text-right">Accion</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($loan->installments as $installment)
                                                @php
                                                    $isAfterCutDate = $installment->due_date->toDateString() > $cutDateString;
                                                    $isCapitalAdvance = $installment->due_date->toDateString() > $cutMonthEndString;
                                                    $hasLaterPending = $loan->installments->contains(fn ($candidate) => $candidate->number > $installment->number && Money::cents($candidate->remaining_amount) > 0 && ! $candidate->reportedMovement);
                                                    $canAdvanceCapital = $isCapitalAdvance && ! $hasLaterPending;
                                                    $graceLimit = $installment->due_date->copy()->addDays((int) ($loan->delinquency_grace_days ?? 0))->toDateString();
                                                    $delinquencyCents = (! $isAfterCutDate && (float) ($loan->delinquency_rate ?? 0) > 0 && $graceLimit < $cutDateString)
                                                        ? (int) round(Money::cents($installment->contract_amount) * ((float) $loan->delinquency_rate / 100))
                                                        : 0;
                                                @endphp
                                                <tr>
                                                    <td class="px-4 py-3 font-semibold">{{ $installment->number }}/{{ $loan->term_months }}</td>
                                                    <td class="px-4 py-3">{{ $installment->due_date->format('d/m/Y') }}</td>
                                                    <td class="px-4 py-3 text-right">{{ Money::mxn($installment->principal_amount) }}</td>
                                                    <td class="px-4 py-3 text-right">{{ Money::mxn($installment->interest_amount) }}</td>
                                                    <td class="px-4 py-3 text-right font-semibold">{{ Money::mxn(Money::decimal(Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount))) }}</td>
                                                    <td class="px-4 py-3 text-right">
                                                        <form method="POST" action="{{ route('collections.mark-paid', $installment) }}"
                                                            data-confirm-paid
                                                            @if ($isCapitalAdvance) data-force-capital-advance="true" @endif
                                                            @if ($canAdvanceCapital) data-capital-advance-allowed="true" @endif>
                                                            @csrf
                                                            <input name="return_to" type="hidden" value="cut">
                                                            <input name="cut_id" type="hidden" value="{{ $cut->id }}">
                                                            <input name="operated_on" type="hidden" value="{{ $cutDateString }}">
                                                            <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                                            <input name="operator_surcharge_amount" type="hidden" value="0">
                                                            <input name="external_concepts_amount" type="hidden" value="0">
                                                            <input name="additional_charge_amount" type="hidden" value="0">
                                                            <input name="delinquency_amount" type="hidden" value="{{ Money::decimal($delinquencyCents) }}">
                                                            <input name="notes" type="hidden" value="{{ $isCapitalAdvance ? 'Adelanto registrado desde corte' : 'Cobro registrado desde corte' }}">
                                                            <button class="rounded-md px-3 py-1.5 text-xs font-bold {{ $isCapitalAdvance && ! $canAdvanceCapital ? 'cursor-not-allowed bg-slate-200 text-slate-500' : 'bg-[#0d9488] text-white' }}" type="submit" @disabled($isCapitalAdvance && ! $canAdvanceCapital)>
                                                                {{ $isCapitalAdvance ? 'Abonar capital' : 'Pagado' }}
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        @endforeach
                    @endif
                </div>
                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cerrar</button>
                </div>
            </dialog>
        @endif
    @endcan
</x-layouts.app>
