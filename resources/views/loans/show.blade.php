@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $balance = $loan->installments->sum(fn ($installment) => Money::cents($installment->remaining_amount));
    $paid = $loan->installments->sum(fn ($installment) => Money::cents($installment->applied_amount));
    $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
    $today = now('America/Merida')->toDateString();
    $overdueCount = $loan->installments->filter(fn ($installment) => Money::cents($installment->remaining_amount) > 0 && $installment->due_date->toDateString() < $today)->count();
    $interestMethodLabel = $loan->interest_calculation_method === 'outstanding_balance' ? 'saldo insoluto' : 'capital fijo';
    $totalInterest = $loan->installments->sum(fn ($installment) => Money::cents($installment->interest_amount));
@endphp

<x-layouts.app title="{{ $loan->folio }} · {{ $loan->client->first_name }} {{ $loan->client->last_name }}">
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="space-y-6">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-[#0f766e]">{{ $loan->operator?->name }} · {{ $loan->vehicle?->model }} {{ $loan->vehicle?->year }}</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Dia de pago {{ $loan->payment_day }} · {{ $loan->term_months }} meses · tasa {{ number_format(((float) $loan->monthly_rate) * 100, 2) }}% mensual · {{ $interestMethodLabel }} · {{ $loan->vat_enabled ? 'Con IVA' : 'Sin IVA' }}
                        @if (Money::cents($loan->administration_fee ?? 0) > 0)
                            · Gtos Admon {{ Money::mxn($loan->administration_fee) }} fijo mensual
                        @endif
                        @if (($loan->calculation_method ?? 'regular') === 'rounded')
                            · Redondeo a {{ $loan->rounding_multiple === 100 ? 'centenas' : 'decenas' }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @can('loans.formalize')
                        <a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" href="{{ route('loans.edit', $loan) }}">Editar</a>
                    @endcan
                    <button class="inline-flex items-center gap-2 rounded-md bg-[#0d9488] px-3 py-2 text-sm font-bold text-white" type="button" data-open-modal="register-payment-modal">
                        <span class="grid size-5 place-items-center rounded bg-white/15 text-xs">$</span>
                        Registrar cobro
                    </button>
                    <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-documents-modal">Expediente</button>
                    @if ($loan->status === 'active')
                        <button class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700" type="button" data-open-modal="settle-loan-modal">Liquidar</button>
                    @endif
                    <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-investors-modal">Inversionistas</button>
                    <a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" href="https://wa.me/52{{ preg_replace('/\D+/', '', $loan->client->phone) }}" target="_blank" rel="noreferrer">WhatsApp</a>
                </div>
            </div>

            <dl class="mt-5 grid gap-3 md:grid-cols-4">
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Capital</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn($loan->capital) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Contrato</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn($loan->contract_total) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Aplicado</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn(Money::decimal($paid)) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Saldo</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn(Money::decimal($balance)) }}</dd>
                </div>
            </dl>
            @if ($overdueCount > 0)
                <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                    {{ $overdueCount }} letra(s) vencida(s) pendiente(s) de pago.
                </div>
            @endif
            @if ($loan->status !== 'active' && $loan->settlement_reason)
                <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <span class="font-bold">Credito liquidado:</span> {{ StatusLabels::settlementReason($loan->settlement_reason) }} · {{ $loan->settled_at?->format('d/m/Y H:i') }}
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Origen del desembolso</h3>
                <p class="mt-1 text-sm text-slate-500">Entrega de fondos relacionada con este prestamo.</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($loan->fundDisbursements as $disbursement)
                    <div class="grid gap-3 p-5 text-sm md:grid-cols-6">
                        <div>
                            <p class="text-slate-500">Fecha entrega</p>
                            <p class="font-bold text-slate-950">{{ $disbursement->delivered_on->format('d/m/Y') }}</p>
                            @if ($disbursement->is_delivery_date_inferred)
                                <p class="mt-1 text-xs text-amber-700">Inferida historica</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-slate-500">Importe</p>
                            <p class="font-bold text-slate-950">{{ Money::mxn($disbursement->amount) }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Operador</p>
                            <p class="font-bold text-slate-950">{{ $disbursement->operator?->name }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Corte</p>
                            @if ($disbursement->weeklyCut)
                                <a class="font-bold text-[#0f766e]" href="{{ route('cuts.show', $disbursement->weeklyCut) }}">
                                    {{ $disbursement->weeklyCut->settlement_on?->format('d/m/Y') ?? $disbursement->weeklyCut->period_ends_on->format('d/m/Y') }}
                                </a>
                            @else
                                <p class="font-bold text-slate-950">Fuera de corte</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-slate-500">Registro</p>
                            <p class="font-bold text-slate-950">{{ $disbursement->registeredBy?->name ?? 'Sistema' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-500">Origen capital</p>
                            <p class="font-bold text-slate-950">{{ ucfirst(str_replace('_', ' ', (string) $disbursement->capital_source)) }}</p>
                        </div>
                    </div>
                @empty
                    <p class="p-5 text-sm text-slate-500">Este prestamo no tiene origen de desembolso registrado. Puede tratarse de informacion historica anterior al control de cortes.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="font-bold text-slate-950">Inversionistas del prestamo</h3>
                    <p class="mt-1 text-sm text-slate-500">Capital aportado y porcentaje pactado sobre intereses del credito.</p>
                </div>
                <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="loan-investors-modal">Editar inversionistas</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Participante</th>
                            <th class="px-5 py-3 text-right">Capital</th>
                            <th class="px-5 py-3 text-right">% Interes</th>
                            <th class="px-5 py-3 text-right">Interes estimado</th>
                            <th class="px-5 py-3">Rol</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($loan->investments as $investment)
                            @php
                                $interestSharePercent = (float) $investment->investor_share_rate * 100;
                                $estimatedInterest = (int) round($totalInterest * (float) $investment->investor_share_rate);
                            @endphp
                            <tr>
                                <td class="px-5 py-3 font-semibold text-slate-950">{{ $investment->investor?->name }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::mxn($investment->amount) }}</td>
                                <td class="px-5 py-3 text-right">{{ number_format($interestSharePercent, 2) }}%</td>
                                <td class="px-5 py-3 text-right">{{ Money::mxn(Money::decimal($estimatedInterest)) }}</td>
                                <td class="px-5 py-3 text-slate-500">Inversionista</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="5">Este prestamo aun no tiene inversionistas configurados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Calendario contractual</h3>
                <p class="mt-1 text-sm text-slate-500">El saldo solo baja cuando un cobro se confirma/aplica.</p>
            </div>
            <div class="max-h-[560px] overflow-x-auto overflow-y-auto">
                <table class="w-full min-w-[1180px] text-left text-sm">
                    <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Letras</th>
                            <th class="px-4 py-3">Vence</th>
                            <th class="px-4 py-3 text-right">Mensualidad</th>
                            <th class="px-4 py-3 text-right">Amortizacion</th>
                            <th class="px-4 py-3 text-right">Gtos Admon</th>
                            <th class="px-4 py-3 text-right">Intereses</th>
                            <th class="px-4 py-3 text-right">Iva Intereses</th>
                            <th class="px-4 py-3 text-right">Capital Vivo</th>
                            <th class="px-4 py-3">Estatus</th>
                            <th class="px-4 py-3 text-right">Pagado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($loan->installments as $installment)
                            @php
                                $movement = $installment->reportedMovement;
                                $isOverdue = ! $movement && Money::cents($installment->remaining_amount) > 0 && $installment->due_date->toDateString() < $today;
                                $statusLabel = $movement && $movement->confirmation_status === 'reported'
                                    ? StatusLabels::movement($movement->confirmation_status)
                                    : ($isOverdue ? StatusLabels::installment('overdue') : StatusLabels::installment($installment->status));
                                $statusClass = $movement && $movement->confirmation_status === 'reported'
                                    ? 'bg-amber-50 text-amber-700'
                                    : ($isOverdue ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-700');
                            @endphp
                            <tr class="{{ $isOverdue ? 'bg-red-50/35' : ($next?->id === $installment->id ? 'bg-[#e6f7f4]/40' : '') }}">
                                <td class="px-4 py-2 font-semibold">{{ $installment->number }}</td>
                                <td class="px-4 py-2">{{ $installment->due_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->contract_amount) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->principal_amount) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->administration_fee_amount ?? 0) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->interest_amount) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->interest_vat_amount) }}</td>
                                <td class="px-4 py-2 text-right">{{ Money::mxn($installment->capital_balance) }}</td>
                                <td class="px-4 py-2">
                                    <span class="{{ $statusClass }} rounded px-2 py-1 text-xs font-bold">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @if (Money::cents($installment->remaining_amount) > 0 && ! $movement)
                                        <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid>
                                            @csrf
                                            <input name="return_to" type="hidden" value="loan">
                                            <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                            <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                            <input name="operator_surcharge_amount" type="hidden" value="0">
                                            <input name="external_concepts_amount" type="hidden" value="0">
                                            <button class="rounded-md bg-[#0d9488] px-2 py-1 text-xs font-bold text-white" type="submit">Pagado</button>
                                        </form>
                                    @else
                                        <span class="text-xs font-semibold text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Movimientos</h3>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($loan->movements as $movement)
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $movement->folio }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $movement->operated_on->format('d/m/Y') }} · {{ $movement->type }}</p>
                            </div>
                            <span class="rounded px-2 py-1 text-xs font-bold {{ $movement->confirmation_status === 'applied' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ StatusLabels::movement($movement->confirmation_status) }}</span>
                        </div>
                        <dl class="mt-3 grid gap-2 text-sm md:grid-cols-3">
                            <div>
                                <dt class="text-slate-500">Pagaré</dt>
                                <dd class="font-bold">{{ Money::mxn($movement->contract_amount) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Recargo</dt>
                                <dd class="font-bold">{{ Money::mxn($movement->operator_surcharge_amount) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">GPS</dt>
                                <dd class="font-bold">{{ Money::mxn($movement->external_concepts_amount) }}</dd>
                            </div>
                        </dl>
                        @can('payments.confirm')
                            @if ($movement->confirmation_status === 'reported')
                                <form class="mt-3" method="POST" action="{{ route('payments.confirm', $movement) }}">
                                    @csrf
                                    <button class="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white" type="submit">Confirmar y aplicar</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="p-5 text-sm text-slate-500">Sin movimientos.</p>
                @endforelse
            </div>
        </section>
    </div>

    <dialog id="register-payment-modal" class="w-[min(92vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('payments.store', $loan) }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Registrar cobro</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</h3>
            </div>
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="type">Tipo</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="type" name="type">
                        <option value="ordinary">Mensualidad</option>
                        <option value="partial">Abono parcial</option>
                        <option value="advance">Abono a capital: cuotas completas desde el final</option>
                    </select>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="operated_on">Fecha</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operated_on" name="operated_on" type="date" value="{{ now('America/Merida')->toDateString() }}">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="contract_amount">Monto contractual</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="contract_amount" name="contract_amount" type="number" step="0.01" value="{{ $next?->remaining_amount ?? '0.00' }}">
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="operator_surcharge_amount">Recargo operador</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operator_surcharge_amount" name="operator_surcharge_amount" type="number" step="0.01" value="0">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="external_concepts_amount">GPS/otros</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="external_concepts_amount" name="external_concepts_amount" type="number" step="0.01" value="0">
                    </div>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="notes">Notas</label>
                    <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Guardar por confirmar</button>
            </div>
        </form>
    </dialog>

    <dialog id="loan-investors-modal" class="w-[min(96vw,880px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('loans.investments.store', $loan) }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Inversionistas</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</h3>
                <p class="mt-1 text-sm text-slate-500">La suma debe cubrir {{ Money::mxn($loan->capital) }} y el 100% de intereses. No hay inversionista default.</p>
            </div>
            <div class="space-y-4 px-5 py-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Inversionista</th>
                                <th class="px-3 py-2 text-right">Disponible</th>
                                <th class="px-3 py-2 text-right">Capital que aporta</th>
                                <th class="px-3 py-2 text-right">% de intereses</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @for ($index = 0; $index < 6; $index++)
                                @php
                                    $investment = $loan->investments->values()->get($index);
                                    $oldInvestorId = old("investors.$index.investor_id", $investment?->investor_id);
                                @endphp
                                <tr>
                                    <td class="px-3 py-2">
                                        <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="investors[{{ $index }}][investor_id]">
                                            <option value="">Seleccionar</option>
                                            @foreach ($investors as $investor)
                                                <option value="{{ $investor->id }}" @selected((string) $oldInvestorId === (string) $investor->id)>{{ $investor->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-500">
                                        @if ($investment)
                                            {{ Money::mxn(Money::decimal(Money::cents($investment->investor?->available_capital) + Money::cents($investment->amount))) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-right text-sm" name="investors[{{ $index }}][capital_amount]" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old("investors.$index.capital_amount", $investment?->amount) }}">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-right text-sm" name="investors[{{ $index }}][interest_share_percent]" type="number" step="0.0001" min="0" max="100" placeholder="0" value="{{ old("investors.$index.interest_share_percent", $investment ? number_format((float) $investment->investor_share_rate * 100, 4, '.', '') : null) }}">
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-slate-500">La suma de capital debe ser exactamente {{ Money::mxn($loan->capital) }} y la suma de porcentajes debe ser exactamente 100%.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Guardar inversionistas</button>
            </div>
        </form>
    </dialog>

    <dialog id="loan-documents-modal" class="w-[min(94vw,760px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <div class="border-b border-slate-200 px-5 py-4">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Expediente</p>
            <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</h3>
        </div>
        <div class="grid max-h-[74vh] gap-5 overflow-y-auto px-5 py-4 lg:grid-cols-[1fr_280px]">
            <div class="space-y-2 text-sm">
                @forelse ($loan->documents as $document)
                    <div class="rounded-md bg-slate-50 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $document->original_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ number_format($document->size / 1024, 1) }} KB · {{ StatusLabels::document($document->status) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <a class="grid size-8 place-items-center rounded-md border border-slate-200 bg-white text-slate-600 hover:text-[#0f766e]" href="{{ route('documents.download', $document) }}" title="Descargar archivo">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                                </a>
                                <form method="POST" action="{{ route('documents.destroy', $document) }}" data-confirm-document-delete>
                                    @csrf
                                    @method('DELETE')
                                    <button class="grid size-8 place-items-center rounded-md border border-red-100 bg-white text-red-600 hover:bg-red-50" type="submit" title="Eliminar archivo">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                        @if ($document->notes)
                            <p class="mt-2 text-xs text-slate-600">{{ $document->notes }}</p>
                        @endif
                    </div>
                @empty
                    <p class="rounded-md bg-slate-50 p-4 text-slate-500">No hay documentos cargados.</p>
                @endforelse
            </div>
            <form class="space-y-3 rounded-md bg-slate-50 p-4" method="POST" action="{{ route('documents.store', $loan) }}" enctype="multipart/form-data">
                @csrf
                <h4 class="font-bold text-slate-950">Subir archivo</h4>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="document_name">Nombre del archivo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="document_name" name="name" type="text" placeholder="Factura del vehiculo" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="document_notes">Nota opcional</label>
                    <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="document_notes" name="notes" rows="3" placeholder="Detalle interno"></textarea>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="document_file">Archivo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#e6f7f4] file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#0f766e]" id="document_file" name="file" type="file" required>
                    <p class="mt-1 text-xs text-slate-500">Limite menor a 100 MB.</p>
                </div>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Guardar archivo</button>
            </form>
        </div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
            <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cerrar</button>
        </div>
    </dialog>

    @if ($loan->status === 'active')
        <dialog id="settle-loan-modal" class="w-[min(92vw,460px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <form method="POST" action="{{ route('loans.settle', $loan) }}">
                @csrf
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-red-700">Liquidar credito</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</h3>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <p class="text-sm leading-6 text-slate-600">Cierra el credito para Orvix; el operador puede continuar la cobranza fuera del sistema si aplica.</p>
                    @if ($settlementQuote)
                        <div class="rounded-md bg-slate-50 p-3">
                            <p class="text-sm text-slate-500">Saldo calculado para liquidar hoy</p>
                            <p class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn(Money::decimal($settlementQuote['total_cents'])) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Incluye capital de cuotas futuras y solo intereses vencidos o del mes corriente. No incluye intereses futuros.</p>
                        </div>
                    @endif
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="settled_on">Fecha de liquidacion</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="settled_on" name="settled_on" type="date" value="{{ now('America/Merida')->toDateString() }}">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="settlement_reason">Motivo de liquidacion</label>
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="settlement_reason" name="settlement_reason" required>
                            <option value="pronto_pago_cliente">Pronto pago del cliente</option>
                            <option value="dejo_de_pagar">Dejo de pagar; cobrador liquida</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                    <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white" type="submit">Liquidar credito</button>
                </div>
            </form>
        </dialog>
    @endif
</x-layouts.app>
