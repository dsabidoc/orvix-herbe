@php
    use App\Support\Money;
    use Carbon\CarbonImmutable;

    $modeLabels = [
        'complete' => 'Cartera completa',
        'overdue' => 'Solo vencidos',
        'current' => 'Al corriente',
        'due_today' => 'Vencen hoy',
        'upcoming' => 'Proximos vencimientos',
    ];

    $money = fn (int $cents) => Money::mxn(Money::decimal($cents));
    $filterQuery = collect(request()->except(['page', 'loan']))->filter(fn ($value) => $value !== null && $value !== '')->all();
@endphp

<x-layouts.app title="Cartera y saldos">
    <div class="no-print mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Cartera completa</p>
            <p class="mt-1 text-sm text-slate-600">Fecha de corte: <strong>{{ $report['cutoff']->format('d/m/Y') }}</strong> · Proximos hasta: <strong>{{ $report['upcoming_end']->format('d/m/Y') }}</strong></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm" href="{{ route('portfolio-balances.export', $filterQuery) }}">Exportar CSV</a>
            <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white shadow-sm" type="button" onclick="window.print()">Imprimir</button>
        </div>
    </div>

    <form class="no-print mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm" method="GET">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="cutoff_date">Fecha de corte / simulacion</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="cutoff_date" name="cutoff_date" type="date" value="{{ $filters['cutoff_date'] }}">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="operator_id">Operador</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operator_id" name="operator_id">
                    <option value="">Todos</option>
                    @unless(auth()->user()->hasRole('operador-cartera'))
                        <option value="none" @selected(($filters['operator_id'] ?? '') === 'none')>Sin operador asignado</option>
                    @endunless
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->id }}" @selected((string) ($filters['operator_id'] ?? '') === (string) $operator->id)>{{ $operator->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-semibold text-slate-700" for="q">Buscar</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cliente, vehiculo, folio, operador, placas o VIN">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="upcoming_range">Proximos</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="upcoming_range" name="upcoming_range">
                    <option value="7" @selected(($filters['upcoming_range'] ?? '') === '7')>7 dias</option>
                    <option value="15" @selected(($filters['upcoming_range'] ?? '') === '15')>15 dias</option>
                    <option value="30" @selected(($filters['upcoming_range'] ?? '30') === '30')>30 dias</option>
                    <option value="month" @selected(($filters['upcoming_range'] ?? '') === 'month')>Resto del mes</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="payment_day">Dia pago</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="payment_day" name="payment_day" type="number" min="1" max="31" value="{{ $filters['payment_day'] ?? '' }}">
            </div>
        </div>

        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="loan_status">Estado prestamo</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="loan_status" name="loan_status">
                    <option value="">Todos no cancelados</option>
                    <option value="active" @selected(($filters['loan_status'] ?? '') === 'active')>Activo</option>
                    <option value="settled" @selected(($filters['loan_status'] ?? '') === 'settled')>Liquidado</option>
                    <option value="formalizing" @selected(($filters['loan_status'] ?? '') === 'formalizing')>Formalizando</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="overdue_presence">Saldo vencido</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="overdue_presence" name="overdue_presence">
                    <option value="">Todos</option>
                    <option value="with" @selected(($filters['overdue_presence'] ?? '') === 'with')>Con saldo vencido</option>
                    <option value="without" @selected(($filters['overdue_presence'] ?? '') === 'without')>Sin saldo vencido</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="late_range">Dias atraso</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="late_range" name="late_range">
                    <option value="">Todos</option>
                    <option value="0" @selected(($filters['late_range'] ?? '') === '0')>0 dias</option>
                    <option value="1-7" @selected(($filters['late_range'] ?? '') === '1-7')>1 a 7</option>
                    <option value="8-30" @selected(($filters['late_range'] ?? '') === '8-30')>8 a 30</option>
                    <option value="31-60" @selected(($filters['late_range'] ?? '') === '31-60')>31 a 60</option>
                    <option value="61-90" @selected(($filters['late_range'] ?? '') === '61-90')>61 a 90</option>
                    <option value="90+" @selected(($filters['late_range'] ?? '') === '90+')>Mas de 90</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="overdue_min">Vencido min.</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="overdue_min" name="overdue_min" type="number" min="0" step="0.01" value="{{ $filters['overdue_min'] ?? '' }}">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="sort">Ordenar</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="sort" name="sort">
                    <option value="">Recomendado</option>
                    <option value="operator" @selected(($filters['sort'] ?? '') === 'operator')>Operador</option>
                    <option value="client" @selected(($filters['sort'] ?? '') === 'client')>Cliente</option>
                    <option value="next_due" @selected(($filters['sort'] ?? '') === 'next_due')>Proxima fecha</option>
                    <option value="overdue_count" @selected(($filters['sort'] ?? '') === 'overdue_count')>Mensualidades vencidas</option>
                    <option value="max_late_days" @selected(($filters['sort'] ?? '') === 'max_late_days')>Dias maximos</option>
                    <option value="overdue_balance" @selected(($filters['sort'] ?? '') === 'overdue_balance')>Saldo vencido</option>
                    <option value="pending_balance" @selected(($filters['sort'] ?? '') === 'pending_balance')>Saldo total</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="direction">Direccion</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="direction" name="direction">
                    <option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Ascendente</option>
                    <option value="desc" @selected(($filters['direction'] ?? '') === 'desc')>Descendente</option>
                </select>
            </div>
        </div>

        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-2">
                @foreach ($modeLabels as $mode => $label)
                    <label class="cursor-pointer rounded-md border px-3 py-2 text-sm font-bold {{ ($filters['mode'] ?? 'complete') === $mode ? 'border-[#0d9488] bg-[#e6f7f4] text-[#0f766e]' : 'border-slate-200 bg-white text-slate-600' }}">
                        <input class="sr-only" type="radio" name="mode" value="{{ $mode }}" @checked(($filters['mode'] ?? 'complete') === $mode)>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            <div class="flex gap-2">
                <a class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" href="{{ route('portfolio-balances.index') }}">Limpiar</a>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Aplicar filtros</button>
            </div>
        </div>
    </form>

    <section class="no-print mb-4 max-w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Resumen por operador</h3>
            <p class="mt-1 text-sm text-slate-500">Totales calculados con los filtros activos.</p>
        </div>
        <div class="divide-y divide-slate-100 md:hidden">
            @forelse ($report['operator_rows'] as $operatorRow)
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-[#0f766e]">{{ $operatorRow['operator_name'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $operatorRow['clients_count'] }} clientes · {{ $operatorRow['loans_count'] }} prestamos</p>
                        </div>
                        <span class="{{ $operatorRow['collection_state']['class'] }} shrink-0 rounded px-2 py-1 text-xs font-bold">{{ $operatorRow['collection_state']['label'] }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-500">Pagares</dt>
                            <dd class="font-semibold">{{ $operatorRow['pending_installments_count'] }} pendientes · {{ $operatorRow['overdue_installments_count'] }} vencidos</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Vehiculos atraso</dt>
                            <dd class="font-semibold">{{ $operatorRow['vehicles_with_overdue_count'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Saldo vencido</dt>
                            <dd class="font-semibold text-red-700">{{ $money($operatorRow['overdue_cents']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Saldo total</dt>
                            <dd class="font-semibold">{{ $money($operatorRow['pending_cents']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Max atraso</dt>
                            <dd class="font-semibold">{{ $operatorRow['max_late_days'] }} dias</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Proximo</dt>
                            <dd class="font-semibold">{{ $operatorRow['next_due_date'] ? CarbonImmutable::parse($operatorRow['next_due_date'])->format('d/m/Y') : '-' }}</dd>
                        </div>
                    </dl>
                    <a class="mt-4 inline-flex rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" href="{{ route('portfolio-balances.index', array_merge($filterQuery, ['operator_id' => $operatorRow['operator_id'] ?? 'none'])) }}">Ver detalle</a>
                </article>
            @empty
                <p class="p-5 text-center text-sm text-slate-500">No se encontraron registros para la fecha de corte y filtros seleccionados.</p>
            @endforelse
        </div>
        <div class="hidden w-full overflow-x-auto md:block">
            <table class="w-full table-fixed text-left text-xs xl:text-sm">
                <colgroup>
                    <col class="w-[16%]">
                    <col class="w-[10%]">
                    <col class="w-[12%]">
                    <col class="w-[15%]">
                    <col class="w-[14%]">
                    <col class="w-[12%]">
                    <col class="w-[10%]">
                    <col class="w-[11%]">
                </colgroup>
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Operador</th>
                        <th class="px-3 py-3 text-right">Clientes / prestamos</th>
                        <th class="px-3 py-3 text-right">Pagares</th>
                        <th class="px-3 py-3 text-right">Saldos</th>
                        <th class="px-3 py-3 text-right">Atraso</th>
                        <th class="px-3 py-3">Proximo</th>
                        <th class="px-3 py-3">Estado</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($report['operator_rows'] as $operatorRow)
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3 font-semibold text-[#0f766e]">{{ $operatorRow['operator_name'] }}</td>
                            <td class="px-3 py-3 text-right">
                                <p class="font-semibold">{{ $operatorRow['clients_count'] }} clientes</p>
                                <p class="text-xs text-slate-500">{{ $operatorRow['loans_count'] }} prestamos</p>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <p class="font-semibold">{{ $operatorRow['pending_installments_count'] }} pendientes</p>
                                <p class="text-xs text-red-600">{{ $operatorRow['overdue_installments_count'] }} vencidos</p>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <p class="font-semibold text-red-700">{{ $money($operatorRow['overdue_cents']) }}</p>
                                <p class="text-xs text-slate-500">{{ $money($operatorRow['pending_cents']) }} total</p>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <p class="font-semibold">{{ $operatorRow['max_late_days'] }} dias</p>
                                <p class="text-xs text-slate-500">{{ $operatorRow['vehicles_with_overdue_count'] }} vehiculos</p>
                            </td>
                            <td class="px-3 py-3">{{ $operatorRow['next_due_date'] ? CarbonImmutable::parse($operatorRow['next_due_date'])->format('d/m/Y') : '-' }}</td>
                            <td class="px-3 py-3">
                                <span class="{{ $operatorRow['collection_state']['class'] }} inline-flex rounded px-2 py-1 text-xs font-bold">{{ $operatorRow['collection_state']['label'] }}</span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <a class="inline-flex rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700" href="{{ route('portfolio-balances.index', array_merge($filterQuery, ['operator_id' => $operatorRow['operator_id'] ?? 'none'])) }}">Detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-8 text-center text-slate-500" colspan="8">No se encontraron registros para la fecha de corte y filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="no-print max-w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Detalle de cartera</h3>
            <p class="mt-1 text-sm text-slate-500">Vista por pagare pendiente o vencido; si un prestamo debe varios meses, aparece una fila por cada mensualidad.</p>
        </div>
        <div class="divide-y divide-slate-100 md:hidden">
            @forelse ($loanRows as $row)
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-950">{{ $row['vehicle_name'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $row['vehicle_identifier'] ?: '-' }}</p>
                        </div>
                        <p class="shrink-0 text-right font-bold text-red-700">{{ $money($row['overdue_cents']) }}</p>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-500">Numero de pagos</dt>
                            <dd class="font-semibold">{{ $row['payment_progress'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Pago</dt>
                            <dd class="font-semibold">{{ $money($row['payment_cents']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Fecha pagare</dt>
                            <dd class="font-semibold">{{ $row['due_date'] ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Dias de atraso</dt>
                            <dd class="font-semibold">{{ $row['late_days'] }} dias</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Mens vencida</dt>
                            <dd class="font-semibold">{{ $row['overdue_installments_count'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Cliente</dt>
                            <dd class="font-semibold text-[#0f766e]">{{ $row['client_name'] }}</dd>
                        </div>
                    </dl>
                </article>
            @empty
                <p class="p-5 text-center text-sm text-slate-500">No se encontraron registros para la fecha de corte y filtros seleccionados.</p>
            @endforelse
        </div>
        <div class="hidden w-full overflow-x-auto md:block">
            <table class="w-full table-fixed text-left text-xs xl:text-sm">
                <colgroup>
                    <col class="w-[22%]">
                    <col class="w-[11%]">
                    <col class="w-[12%]">
                    <col class="w-[12%]">
                    <col class="w-[11%]">
                    <col class="w-[10%]">
                    <col class="w-[12%]">
                    <col class="w-[10%]">
                </colgroup>
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Auto</th>
                        <th class="px-3 py-3">Num. pagos</th>
                        <th class="px-3 py-3 text-right">Pago</th>
                        <th class="px-3 py-3">Fecha pagare</th>
                        <th class="px-3 py-3 text-right">Dias atraso</th>
                        <th class="px-3 py-3 text-right">Mens vencida</th>
                        <th class="px-3 py-3 text-right">Suma vencidas</th>
                        <th class="px-3 py-3">Cliente</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($loanRows as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3">
                                {{ $row['vehicle_name'] }}
                                <p class="text-xs text-slate-500">{{ $row['vehicle_identifier'] ?: '-' }}</p>
                            </td>
                            <td class="px-3 py-3 font-semibold">{{ $row['payment_progress'] }}</td>
                            <td class="px-3 py-3 text-right font-semibold">{{ $money($row['payment_cents']) }}</td>
                            <td class="px-3 py-3">{{ $row['due_date'] ?? '-' }}</td>
                            <td class="px-3 py-3 text-right">{{ $row['late_days'] }} dias</td>
                            <td class="px-3 py-3 text-right">{{ $row['overdue_installments_count'] }}</td>
                            <td class="px-3 py-3 text-right font-semibold text-red-700">{{ $money($row['overdue_cents']) }}</td>
                            <td class="px-3 py-3 font-semibold text-[#0f766e]">{{ $row['client_name'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-8 text-center text-slate-500" colspan="8">No se encontraron registros para la fecha de corte y filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="no-print mt-4">{{ $loanRows->links() }}</div>

    <section class="print-only print-sheet">
        <div class="mb-3">
            <p class="text-[10px] text-slate-500">Fecha de exportacion: {{ CarbonImmutable::now('America/Merida')->format('d/m/Y H:i') }}</p>
            <h3 class="mt-1 text-base font-bold text-slate-950">Cartera y saldos</h3>
            <p class="text-xs text-slate-600">Corte: {{ $report['cutoff']->format('d/m/Y') }}</p>
        </div>

        <div class="mb-4">
            <h3 class="mb-2 text-sm font-bold text-slate-950">Resumen por operador</h3>
            <table class="cut-print-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Operador</th>
                        <th class="text-right">Clientes</th>
                        <th class="text-right">Prestamos</th>
                        <th class="text-right">Pagares pendientes</th>
                        <th class="text-right">Pagares vencidos</th>
                        <th class="text-right">Vehiculos atraso</th>
                        <th class="text-right">Saldo vencido</th>
                        <th class="text-right">Saldo total</th>
                        <th class="text-right">Max atraso</th>
                        <th>Proximo</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['operator_rows'] as $operatorRow)
                        <tr>
                            <td>{{ $operatorRow['operator_name'] }}</td>
                            <td class="text-right">{{ $operatorRow['clients_count'] }}</td>
                            <td class="text-right">{{ $operatorRow['loans_count'] }}</td>
                            <td class="text-right">{{ $operatorRow['pending_installments_count'] }}</td>
                            <td class="text-right">{{ $operatorRow['overdue_installments_count'] }}</td>
                            <td class="text-right">{{ $operatorRow['vehicles_with_overdue_count'] }}</td>
                            <td class="text-right">{{ $money($operatorRow['overdue_cents']) }}</td>
                            <td class="text-right">{{ $money($operatorRow['pending_cents']) }}</td>
                            <td class="text-right">{{ $operatorRow['max_late_days'] }} dias</td>
                            <td>{{ $operatorRow['next_due_date'] ? CarbonImmutable::parse($operatorRow['next_due_date'])->format('d/m/Y') : '-' }}</td>
                            <td>{{ $operatorRow['collection_state']['label'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11">No se encontraron registros para la fecha de corte y filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            <h3 class="mb-2 text-sm font-bold text-slate-950">Detalle de cartera</h3>
            <table class="cut-print-table w-full text-left text-sm">
                <colgroup>
                    <col style="width: 25%">
                    <col style="width: 8%">
                    <col style="width: 12%">
                    <col style="width: 12%">
                    <col style="width: 11%">
                    <col style="width: 10%">
                    <col style="width: 12%">
                    <col style="width: 10%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Auto</th>
                        <th>Numero de pagos</th>
                        <th class="text-right">Pago</th>
                        <th>Fecha pagare</th>
                        <th class="text-right">Dias de atraso</th>
                        <th class="text-right">Mens vencida</th>
                        <th class="text-right">Suma vencidas</th>
                        <th>Cliente</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['detail_rows'] as $row)
                        <tr>
                            <td>{{ $row['vehicle_name'] }} {{ $row['vehicle_identifier'] ? '· '.$row['vehicle_identifier'] : '' }}</td>
                            <td>{{ $row['payment_progress'] }}</td>
                            <td class="text-right">{{ $money($row['payment_cents']) }}</td>
                            <td>{{ $row['due_date'] ?? '-' }}</td>
                            <td class="text-right">{{ $row['late_days'] }} dias</td>
                            <td class="text-right">{{ $row['overdue_installments_count'] }}</td>
                            <td class="text-right">{{ $money($row['overdue_cents']) }}</td>
                            <td>{{ $row['client_name'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">No se encontraron registros para la fecha de corte y filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($selectedLoan)
        <section class="no-print mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" id="pagares">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Detalle de pagares</p>
                <h3 class="mt-1 font-bold text-slate-950">{{ $selectedLoan['folio'] }} · {{ $selectedLoan['client_name'] }}</h3>
            </div>
            <div class="divide-y divide-slate-100 md:hidden">
                @foreach ($selectedLoan['installments'] as $installment)
                    <article class="{{ $installment['is_overdue'] ? 'bg-red-50/30' : '' }} p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-950">Pagare {{ $installment['number'] }} · {{ $installment['progress'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">Vence {{ $installment['due_date'] }}</p>
                            </div>
                            <span class="{{ $installment['status']['class'] }} shrink-0 rounded px-2 py-1 text-xs font-bold">{{ $installment['status']['label'] }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-slate-500">Contractual</dt>
                                <dd class="font-semibold">{{ $money($installment['contract_cents']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Pagado</dt>
                                <dd class="font-semibold">{{ $money($installment['paid_cents']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Pendiente</dt>
                                <dd class="font-semibold">{{ $money($installment['pending_cents']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Saldo vencido</dt>
                                <dd class="font-semibold text-red-700">{{ $money($installment['overdue_cents']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Dias atraso</dt>
                                <dd class="font-semibold">{{ $installment['late_days'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Ultimo pago</dt>
                                <dd class="font-semibold">{{ $installment['last_payment_date'] ?: '-' }}</dd>
                            </div>
                        </dl>
                        <a class="mt-4 inline-flex rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" href="{{ route('loans.show', $selectedLoan['loan_public_id']) }}">Abrir prestamo</a>
                    </article>
                @endforeach
            </div>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full table-fixed text-left text-xs xl:text-sm">
                    <colgroup>
                        <col class="w-[8%]">
                        <col class="w-[9%]">
                        <col class="w-[10%]">
                        <col class="w-[12%]">
                        <col class="w-[10%]">
                        <col class="w-[11%]">
                        <col class="w-[9%]">
                        <col class="w-[11%]">
                        <col class="w-[10%]">
                        <col class="w-[10%]">
                    </colgroup>
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Pagare</th>
                            <th class="px-3 py-3">Plazo</th>
                            <th class="px-3 py-3">Vence</th>
                            <th class="px-3 py-3 text-right">Contractual</th>
                            <th class="px-3 py-3 text-right">Pagado</th>
                            <th class="px-3 py-3 text-right">Pendiente</th>
                            <th class="px-3 py-3 text-right">Dias atraso</th>
                            <th class="px-3 py-3 text-right">Saldo vencido</th>
                            <th class="px-3 py-3">Estado</th>
                            <th class="px-3 py-3">Ultimo pago</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($selectedLoan['installments'] as $installment)
                            <tr class="{{ $installment['is_overdue'] ? 'bg-red-50/30' : '' }}">
                                <td class="px-3 py-3 font-semibold">{{ $installment['number'] }}</td>
                                <td class="px-3 py-3">{{ $installment['progress'] }}</td>
                                <td class="px-3 py-3">{{ $installment['due_date'] }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ $money($installment['contract_cents']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $money($installment['paid_cents']) }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ $money($installment['pending_cents']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $installment['late_days'] }}</td>
                                <td class="px-3 py-3 text-right font-semibold text-red-700">{{ $money($installment['overdue_cents']) }}</td>
                                <td class="px-3 py-3"><span class="{{ $installment['status']['class'] }} rounded px-2 py-1 text-xs font-bold">{{ $installment['status']['label'] }}</span></td>
                                <td class="px-3 py-3">{{ $installment['last_payment_date'] ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
