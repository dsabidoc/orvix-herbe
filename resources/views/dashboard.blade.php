@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $kpiStyles = [
        'blue' => ['card' => 'border-blue-200 bg-blue-50/80', 'label' => 'text-blue-700', 'dot' => '#2563eb', 'track' => 'bg-blue-100', 'bar' => 'bg-blue-500'],
        'orange' => ['card' => 'border-orange-200 bg-orange-50/80', 'label' => 'text-orange-700', 'dot' => '#f97316', 'track' => 'bg-orange-100', 'bar' => 'bg-orange-500'],
        'yellow' => ['card' => 'border-yellow-200 bg-yellow-50/80', 'label' => 'text-yellow-700', 'dot' => '#eab308', 'track' => 'bg-yellow-100', 'bar' => 'bg-yellow-500'],
        'green' => ['card' => 'border-emerald-200 bg-emerald-50/80', 'label' => 'text-emerald-700', 'dot' => '#10b981', 'track' => 'bg-emerald-100', 'bar' => 'bg-emerald-500'],
        'red' => ['card' => 'border-red-200 bg-red-50/80', 'label' => 'text-red-700', 'dot' => '#ef4444', 'track' => 'bg-red-100', 'bar' => 'bg-red-500'],
    ];
    $chartKpis = collect($kpis)->filter(fn ($kpi) => $kpi['chartable'] ?? true)->values();
    $chartDisplayTotal = $chartKpis->sum('cents');
    $chartTotal = max(1, $chartDisplayTotal);
    $chartCursor = 0;
    $chartStops = [];

    foreach ($chartKpis as $kpi) {
        $percent = $kpi['cents'] / $chartTotal * 100;
        $color = $kpiStyles[$kpi['color']]['dot'];
        $chartStops[] = "{$color} {$chartCursor}% ".($chartCursor + $percent).'%';
        $chartCursor += $percent;
    }

    $dashboardUser = auth()->user();
    $canCreateLoan = $dashboardUser->can('loans.formalize');
    $canRequestLoan = $dashboardUser->can('applications.create') && ! $canCreateLoan;
    $canRegisterPayment = $dashboardUser->hasRole('operador-cartera') || $dashboardUser->can('payments.confirm') || $dashboardUser->can('payments.report');
@endphp

<x-layouts.app title="Dashboard">
    @if ($canCreateLoan || $canRequestLoan || $canRegisterPayment)
        <div class="mb-4 flex flex-wrap justify-end gap-2">
            @if ($canCreateLoan)
                <a class="inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" href="{{ route('loans.create') }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2z"/></svg>
                    Crear Prestamo
                </a>
            @endif

            @if ($canRequestLoan)
                <a class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" href="{{ route('applications.create') }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
                    Solicitar Prestamo
                </a>
            @endif

            @if ($canRegisterPayment)
                <button class="inline-flex items-center gap-2 rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="quick-payment-modal">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Registrar Cobro
                </button>
            @endif
        </div>
    @endif

    <section class="mb-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-[220px_220px_180px_180px_auto] md:items-end" method="GET" action="{{ route('dashboard') }}">
            @unless (auth()->user()->hasRole('operador-cartera'))
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="operator_id">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" id="operator_id" name="operator_id">
                        <option value="">Todos los operadores</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected((string) $filters['operator_id'] === (string) $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="investor_id">Inversionista</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" id="investor_id" name="investor_id">
                        <option value="">Todos</option>
                        @foreach ($investors as $investor)
                            <option value="{{ $investor->id }}" @selected((string) ($filters['investor_id'] ?? '') === (string) $investor->id)>{{ $investor->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div>
                <label class="text-sm font-semibold text-slate-700" for="period_type">Vista</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" id="period_type" name="period_type">
                    <option value="month" @selected($filters['period_type'] === 'month')>Mes</option>
                    <option value="year" @selected($filters['period_type'] === 'year')>Año</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="period">Periodo</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="period" name="period" type="{{ $filters['period_type'] === 'year' ? 'number' : 'month' }}" value="{{ $filters['period'] }}">
            </div>
            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Filtrar</button>
        </form>
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($kpis as $kpi)
            @php
                $kpiStyle = $kpiStyles[$kpi['color']];
            @endphp
            <article class="{{ $kpiStyle['card'] }} rounded-lg border p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <p class="{{ $kpiStyle['label'] }} text-sm font-bold">{{ $kpi['title'] }}</p>
                    <span class="size-2.5 rounded-full" style="background-color: {{ $kpiStyle['dot'] }}"></span>
                </div>
                <p class="mt-3 break-words text-[clamp(1.35rem,1.7vw,1.5rem)] font-bold leading-tight text-slate-950">{{ $kpi['value'] }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $kpi['caption'] }}</p>
            </article>
        @endforeach
    </div>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-6 lg:grid-cols-[300px_1fr] lg:items-center">
            <div>
                <h3 class="font-bold text-slate-950">Resumen visual</h3>
                <p class="mt-1 text-sm text-slate-500">Liquidacion de hoy, cobranza del periodo y vencidos.</p>
                <div class="mx-auto mt-5 grid size-56 place-items-center rounded-full" style="background: conic-gradient({{ implode(', ', $chartStops) }});">
                    <div class="grid size-28 place-items-center rounded-full bg-white text-center shadow-sm">
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">Total</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ Money::mxn(Money::decimal($chartDisplayTotal)) }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="space-y-4">
                @foreach ($chartKpis as $kpi)
                    @php
                        $style = $kpiStyles[$kpi['color']];
                        $percent = round($kpi['cents'] / $chartTotal * 100);
                    @endphp
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <p class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <span class="size-2.5 rounded-full" style="background-color: {{ $style['dot'] }}"></span>
                                {{ $kpi['title'] }}
                            </p>
                            <span class="text-sm font-bold text-slate-400">{{ $percent }}%</span>
                        </div>
                        <div class="{{ $style['track'] }} mt-2 h-2 rounded-full">
                            <div class="{{ $style['bar'] }} h-2 rounded-full" style="width: {{ max(2, $percent) }}%"></div>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-600">{{ $kpi['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_420px]">
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="font-bold text-slate-950">Cartera reciente</h3>
                    <p class="mt-1 text-sm text-slate-500">Prestamos activos con calendario y saldo real de letras.</p>
                </div>
                <a class="rounded-md bg-[#0d9488] px-3 py-2 text-sm font-semibold text-white" href="{{ route('loans.index') }}">Ver cartera</a>
            </div>
            <div class="overflow-hidden">
                <table class="hidden w-full text-left text-sm md:table">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Cliente</th>
                            <th class="px-5 py-3">Vehiculo</th>
                            <th class="px-5 py-3">Operador</th>
                            <th class="px-5 py-3 text-right">Saldo</th>
                            <th class="px-5 py-3">Proxima</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($loans as $loan)
                            @php
                                $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
                                $balance = $loan->installments->sum(function ($installment) {
                                    $remaining = Money::cents($installment->remaining_amount);
                                    $operational = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

                                    return $operational > 0 ? min($remaining, $operational) : $remaining;
                                });
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $loan) }}">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</a>
                                    <p class="text-xs text-slate-500">{{ $loan->folio }}</p>
                                </td>
                                <td class="px-5 py-3">{{ $loan->vehicle?->model }} {{ $loan->vehicle?->year }}</td>
                                <td class="px-5 py-3">{{ $loan->operator?->name }}</td>
                                <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn(Money::decimal($balance)) }}</td>
                                <td class="px-5 py-3">{{ $next?->due_date?->format('d/m/Y') ?? 'Liquidado' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="divide-y divide-slate-100 md:hidden">
                    @foreach ($loans as $loan)
                        @php
                            $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
                            $balance = $loan->installments->sum(function ($installment) {
                                $remaining = Money::cents($installment->remaining_amount);
                                $operational = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

                                return $operational > 0 ? min($remaining, $operational) : $remaining;
                            });
                        @endphp
                        <a class="block p-4" href="{{ route('loans.show', $loan) }}">
                            <p class="font-semibold text-[#0f766e]">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $loan->vehicle?->model }} · {{ $loan->operator?->name }}</p>
                            <p class="mt-2 text-sm font-semibold">{{ Money::mxn(Money::decimal($balance)) }} · Proxima {{ $next?->due_date?->format('d/m/Y') ?? 'Liquidado' }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Cortes</h3>
                <p class="mt-1 text-sm text-slate-500">Reportado por operador contra efectivo recibido.</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($cuts as $cut)
                    <a class="block p-5 hover:bg-slate-50" href="{{ route('cuts.show', $cut) }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $cut->operator->name }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $cut->period_starts_on->format('d/m/Y') }} · {{ ($cut->submitted_at ?? $cut->created_at)->format('H:i') }}</p>
                            </div>
                            <span class="rounded bg-[#e6f7f4] px-2 py-1 text-xs font-bold uppercase text-[#0f766e]">{{ StatusLabels::cut($cut->status) }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-slate-500">Reportado</dt>
                                <dd class="font-bold">{{ Money::mxn($cut->reported_total) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Diferencia</dt>
                                <dd class="font-bold">{{ Money::mxn($cut->difference_total) }}</dd>
                            </div>
                        </dl>
                    </a>
                @empty
                    <p class="p-5 text-sm text-slate-500">No hay cortes para mostrar.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="font-bold text-slate-950">Operadores demo</h3>
        <div class="mt-4 grid gap-3 md:grid-cols-4">
            @foreach ($operators as $operator)
                <div class="rounded-md bg-slate-50 p-4">
                    <p class="font-semibold text-slate-950">{{ $operator->name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $operator->loans_count }} prestamos activos/asignados</p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($canRegisterPayment)
        <dialog id="quick-payment-modal" class="w-[min(96vw,980px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Cobranza rapida</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Registrar Cobro</h3>
            </div>
            <div class="px-5 py-4">
                <label class="text-sm font-semibold text-slate-700" for="quick_payment_loan">Cliente</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" id="quick_payment_loan" data-quick-payment-select>
                    <option value="">Selecciona un cliente o prestamo</option>
                    @foreach ($quickCollectionLoans as $loan)
                        <option value="{{ $loan->public_id }}">{{ $loan->client->first_name }} {{ $loan->client->last_name }} · {{ $loan->folio }} · {{ $loan->vehicle?->model }}</option>
                    @endforeach
                </select>

                <div class="mt-4 rounded-md border border-slate-200">
                    @forelse ($quickCollectionLoans as $loan)
                        <section class="hidden" data-quick-payment-panel="{{ $loan->public_id }}">
                            <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $loan->folio }} · {{ $loan->vehicle?->model }} {{ $loan->vehicle?->year }} · {{ $loan->operator?->name }}</p>
                            </div>
                            <div class="max-h-[420px] overflow-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3">Letra</th>
                                            <th class="px-4 py-3">Vence</th>
                                            <th class="px-4 py-3 text-right">Saldo</th>
                                            <th class="px-4 py-3 text-right">Accion</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($loan->installments as $installment)
                                            @php
                                                $isOverdue = $installment->due_date->toDateString() < now('America/Merida')->toDateString();
                                                $graceLimit = $installment->due_date->copy()->addDays((int) ($loan->delinquency_grace_days ?? 0))->toDateString();
                                                $delinquencyCents = ((float) ($loan->delinquency_rate ?? 0) > 0 && $graceLimit < now('America/Merida')->toDateString())
                                                    ? (int) round(Money::cents($installment->contract_amount) * ((float) $loan->delinquency_rate / 100))
                                                    : 0;
                                            @endphp
                                            <tr class="{{ $isOverdue ? 'bg-red-50/40' : '' }}">
                                                <td class="px-4 py-3 font-semibold">{{ $installment->number }}</td>
                                                <td class="px-4 py-3">
                                                    {{ $installment->due_date->format('d/m/Y') }}
                                                    @if ($isOverdue)
                                                        <span class="ml-2 rounded bg-red-50 px-2 py-1 text-xs font-bold text-red-700">Vencida</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 text-right font-semibold">{{ Money::mxn($installment->remaining_amount) }}</td>
                                                <td class="px-4 py-3 text-right">
                                                    <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid>
                                                        @csrf
                                                        <input name="return_to" type="hidden" value="dashboard">
                                                        <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                                        <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                                        <input name="operator_surcharge_amount" type="hidden" value="0">
                                                        <input name="external_concepts_amount" type="hidden" value="0">
                                                        <input name="additional_charge_amount" type="hidden" value="0">
                                                        <input name="delinquency_amount" type="hidden" value="{{ Money::decimal($delinquencyCents) }}">
                                                        <button class="rounded-md bg-[#0d9488] px-3 py-2 text-xs font-bold text-white" type="submit">Pagado</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @empty
                        <p class="px-4 py-6 text-sm text-slate-500">No hay pagos pendientes para registrar.</p>
                    @endforelse
                    <p class="px-4 py-6 text-sm text-slate-500" data-quick-payment-empty>Selecciona un cliente para ver sus pagos pendientes.</p>
                </div>
            </div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cerrar</button>
            </div>
        </dialog>
    @endif
</x-layouts.app>
