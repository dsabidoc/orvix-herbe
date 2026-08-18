@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $operationalTotal = $loan->installments->sum(fn ($installment) => Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount));
    $operationalBalance = $loan->installments->sum(fn ($installment) => Money::cents($installment->remaining_amount));
    $operationalPaid = max(0, $operationalTotal - $operationalBalance);
    $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
    $today = now('America/Merida')->toDateString();
    $overdueInstallments = $loan->installments->filter(fn ($installment) => Money::cents($installment->remaining_amount) > 0 && $installment->due_date->toDateString() < $today);
    $overdueCount = $overdueInstallments->count();
    $overdueBalanceCents = $overdueInstallments->sum(fn ($installment) => Money::cents($installment->remaining_amount));
    $interestMethodLabel = $loan->interest_calculation_method === 'outstanding_balance' ? 'saldo insoluto' : 'capital fijo';
    $calculationMethodLabel = match ($loan->calculation_method ?? 'regular') {
        'interest_only' => 'Solo interes sobre capital vigente',
        'rounded' => 'Redondeo',
        default => null,
    };
    $loanUser = auth()->user();
    $isInvestorReadOnly = $loanUser->can('investments.view-own') && ! $loanUser->can('investors.manage');
    $isProviderUser = $loanUser->hasRole('operador-cartera') || $loanUser->hasRole('proveedor');
    $canOperateLoan = ! $isInvestorReadOnly;
    $canReportPayment = ! $isInvestorReadOnly && ($isProviderUser || $loanUser->can('payments.report') || $loanUser->can('payments.confirm'));
    $canSettleLoan = ! $isInvestorReadOnly && ($isProviderUser || $loanUser->can('settlements.authorize') || $loanUser->can('payments.confirm') || $loanUser->can('loans.formalize'));
    $canManageLoanDetails = ! $isInvestorReadOnly && ! $isProviderUser;
    $canViewInvoice = ! $isInvestorReadOnly;
    $canManageInvoice = $canManageLoanDetails && ($loanUser->can('loans.formalize') || $loanUser->can('payments.confirm') || $loanUser->can('documents.manage'));
    $canReverseInstallmentPayment = $loanUser->can('payments.confirm') && ! $isProviderUser;
    $settlementTodayCents = $settlementQuote['total_cents'] ?? 0;
    $vehicleModelTitle = trim((string) ($loan->vehicle?->model ?? ''));
    $vehicleModelTitle = $vehicleModelTitle !== '' ? $vehicleModelTitle : 'Vehiculo sin modelo';
    $vehicleMetaTitle = trim(implode(' ', array_filter([$loan->vehicle?->brand, $loan->vehicle?->year])));
    $vehicleMetaTitle = $vehicleMetaTitle !== '' ? $vehicleMetaTitle : 'Marca y año sin datos';
    $nextDelinquencyCents = 0;
    if ($next && (float) ($loan->delinquency_rate ?? 0) > 0) {
        $graceLimit = $next->due_date->copy()->addDays((int) ($loan->delinquency_grace_days ?? 0))->toDateString();
        if ($graceLimit < $today) {
            $nextDelinquencyCents = (int) round(Money::cents($next->contract_amount) * ((float) $loan->delinquency_rate / 100));
        }
    }
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
                    <p class="text-sm font-semibold text-[#0f766e]">{{ $loan->operator?->name }} · {{ $loan->folio }}</p>
                    <h3 class="mt-1 text-2xl font-bold text-slate-950">{{ $vehicleModelTitle }} · Dia {{ $loan->payment_day }}</h3>
                    <p class="mt-1 text-sm font-semibold text-slate-500">{{ $vehicleMetaTitle }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $loan->client->first_name }} {{ $loan->client->last_name }} · {{ ($loan->calculation_method ?? 'regular') === 'interest_only' ? 'sin plazo fijo proyectado a '.$loan->term_months.' meses' : $loan->term_months.' meses' }} · tasa {{ number_format(((float) $loan->monthly_rate) * 100, 2) }}% mensual · {{ $calculationMethodLabel ?: $interestMethodLabel }} · {{ $loan->vat_enabled ? 'Con IVA' : 'Sin IVA' }}
                        @if (Money::cents($loan->administration_fee ?? 0) > 0)
                            · Gtos Admon {{ Money::mxn($loan->administration_fee) }} fijo mensual
                        @endif
                        @if (($loan->calculation_method ?? 'regular') === 'rounded')
                            · Redondeo a {{ $loan->rounding_multiple === 100 ? 'centenas' : 'decenas' }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($canOperateLoan)
                        @can('loans.formalize')
                            @if ($canManageLoanDetails)
                                <a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" href="{{ route('loans.edit', $loan) }}">Editar</a>
                            @endif
                        @endcan
                        @if ($canReportPayment)
                            <button class="inline-flex items-center gap-2 rounded-md bg-[#0d9488] px-3 py-2 text-sm font-bold text-white" type="button" data-open-modal="register-payment-modal">
                                <span class="grid size-5 place-items-center rounded bg-white/15 text-xs">$</span>
                                Registrar cobro
                            </button>
                        @endif
                        @if ($canManageLoanDetails)
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-documents-modal">Expediente</button>
                        @endif
                        @if ($canViewInvoice)
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-invoice-modal">Factura</button>
                        @endif
                        @if ($canManageLoanDetails)
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-notes-modal">Notas</button>
                        @endif
                        @if ($loan->status === 'active' && $canSettleLoan)
                            <button class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700" type="button" data-open-modal="settle-loan-modal">Liquidar</button>
                        @endif
                        @if ($canManageLoanDetails)
                            <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" type="button" data-open-modal="loan-investors-modal">Inversionistas</button>
                            <a class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700" href="https://wa.me/52{{ preg_replace('/\D+/', '', $loan->client->phone) }}" target="_blank" rel="noreferrer">WhatsApp</a>
                        @endif
                        @can('loans.formalize')
                            @if ($canManageLoanDetails)
                                <form method="POST" action="{{ route('loans.destroy', $loan) }}" data-confirm-delete data-confirm-title="¿Eliminar este prestamo?" data-confirm-message="Se eliminara el prestamo como si no hubiera existido y se regresara el capital tomado a los inversionistas. Si algun retorno ya fue usado o reinvertido, el sistema lo bloqueara.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-300 bg-red-100 px-3 py-2 text-sm font-bold text-red-800" type="submit">Eliminar prestamo</button>
                                </form>
                            @endif
                        @endcan
                    @endif
                </div>
            </div>

            <dl @class([
                'mt-5 grid gap-3 sm:grid-cols-2',
                'xl:grid-cols-6' => $overdueBalanceCents > 0,
                'xl:grid-cols-5' => $overdueBalanceCents <= 0,
            ])>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Capital</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn($loan->capital) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">{{ ($loan->calculation_method ?? 'regular') === 'interest_only' ? 'Interes proyectado' : 'Contrato' }}</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn(Money::decimal($operationalTotal)) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">Aplicado</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn(Money::decimal($operationalPaid)) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-sm text-slate-500">{{ ($loan->calculation_method ?? 'regular') === 'interest_only' ? 'Interes pendiente' : 'Saldo' }}</dt>
                    <dd class="mt-1 font-bold">{{ Money::mxn(Money::decimal($operationalBalance)) }}</dd>
                </div>
                @if ($overdueBalanceCents > 0)
                    <div class="rounded-md bg-red-50 p-3">
                        <dt class="text-sm text-red-700">Vencido</dt>
                        <dd class="mt-1 font-bold text-red-700">{{ Money::mxn(Money::decimal($overdueBalanceCents)) }}</dd>
                    </div>
                @endif
                <div class="rounded-md bg-red-50 p-3">
                    <dt class="text-sm text-red-700">Liquidar hoy</dt>
                    <dd class="mt-1 font-bold text-red-700">{{ Money::mxn(Money::decimal($settlementTodayCents)) }}</dd>
                </div>
            </dl>
            @if ($overdueCount > 0)
                <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                    {{ $overdueCount }} letra(s) vencida(s) pendiente(s) de pago.
                </div>
            @endif
            @if ($loan->is_frozen)
                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    <span class="font-bold">Prestamo congelado:</span> {{ $loan->frozen_reason }} · no se suma a cobranza esperada.
                </div>
            @endif
            @if ($loan->status !== 'active' && $loan->settlement_reason)
                <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <span class="font-bold">Credito liquidado:</span> {{ StatusLabels::settlementReason($loan->settlement_reason) }} · {{ $loan->settled_at?->format('d/m/Y H:i') }}
                </div>
            @endif
        </section>

        <section class="grid gap-4 {{ $canManageLoanDetails && $canViewInvoice ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }}">
            @if ($canManageLoanDetails)
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Control operativo</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Estado cobranza</dt><dd class="font-bold {{ $loan->is_frozen ? 'text-blue-700' : 'text-emerald-700' }}">{{ $loan->is_frozen ? 'Congelado' : 'Activo' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Morosidad</dt><dd class="font-bold">{{ number_format((float) ($loan->delinquency_rate ?? 0), 2) }}% despues de {{ (int) ($loan->delinquency_grace_days ?? 0) }} dias</dd></div>
                </dl>
                @if ($canManageLoanDetails)
                    @if ($loan->is_frozen)
                        <form class="mt-4" method="POST" action="{{ route('loans.unfreeze', $loan) }}">
                            @csrf
                            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Reactivar prestamo</button>
                        </form>
                    @else
                        <button class="mt-4 w-full rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700" type="button" data-open-modal="freeze-loan-modal">Congelar prestamo</button>
                    @endif
                @endif
            </div>
            @endif
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Aval</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-slate-500">Nombre</dt><dd class="font-semibold">{{ $loan->guarantor_name ?: '-' }}</dd></div>
                    <div><dt class="text-slate-500">Celular</dt><dd class="font-semibold">{{ $loan->guarantor_phone ?: '-' }}</dd></div>
                    <div><dt class="text-slate-500">Direccion</dt><dd class="font-semibold">{{ $loan->guarantor_address ?: '-' }}</dd></div>
                </dl>
            </div>
            @if ($canViewInvoice)
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Factura fisica</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Ubicacion</dt><dd class="font-bold">{{ $loan->invoice_holder ?: 'Sin registrar' }}</dd></div>
                    <div><dt class="text-slate-500">Archivo</dt><dd class="font-semibold">{{ $loan->invoiceDocument?->original_name ?: 'Sin factura PDF' }}</dd></div>
                </dl>
                <button class="mt-4 w-full rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700" type="button" data-open-modal="loan-invoice-modal">{{ $canManageInvoice ? 'Gestionar factura' : 'Ver factura' }}</button>
            </div>
            @endif
        </section>

        @if ($canManageLoanDetails)
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
        @endif

        @if ($canManageLoanDetails)
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="font-bold text-slate-950">Inversionistas del prestamo</h3>
                    <p class="mt-1 text-sm text-slate-500">Capital aportado y porcentaje pactado sobre intereses del credito.</p>
                </div>
                @if ($canOperateLoan)
                    <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="loan-investors-modal">Editar inversionistas</button>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Participante</th>
                            <th class="px-5 py-3 text-right">Capital</th>
                            <th class="px-5 py-3 text-right">% Interes</th>
                            <th class="px-5 py-3">Rol</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($loan->investments as $investment)
                            @php
                                $interestSharePercent = (float) $investment->investor_share_rate * 100;
                            @endphp
                            <tr>
                                <td class="px-5 py-3 font-semibold text-slate-950">{{ $investment->investor?->name }}</td>
                                <td class="px-5 py-3 text-right">{{ Money::mxn($investment->amount) }}</td>
                                <td class="px-5 py-3 text-right">{{ number_format($interestSharePercent, 2) }}%</td>
                                <td class="px-5 py-3 text-slate-500">Inversionista</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-6 text-sm text-slate-500" colspan="4">Este prestamo aun no tiene inversionistas configurados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="font-bold text-slate-950">Calendario contractual</h3>
                    <p class="mt-1 text-sm text-slate-500">El saldo solo baja cuando un cobro se confirma/aplica.</p>
                </div>
                @if ($canOperateLoan)
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700" type="button" data-select-overdue-payments>Seleccionar todos los vencidos</button>
                        <form class="hidden" method="POST" action="{{ route('collections.mark-paid.bulk') }}" data-confirm-paid data-bulk-payment-form>
                            @csrf
                            <input name="loan_id" type="hidden" value="{{ $loan->id }}">
                            <input name="return_to" type="hidden" value="loan">
                            <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                            <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Pagar todas las seleccionadas</button>
                        </form>
                    </div>
                @endif
            </div>
            <div class="max-h-[560px] overflow-x-auto overflow-y-auto">
                <table class="w-full min-w-[940px] table-fixed text-left text-sm">
                    <colgroup>
                        <col class="w-[40px]">
                        <col class="w-[56px]">
                        <col class="w-[104px]">
                        <col class="w-[96px]">
                        <col class="w-[128px]">
                        <col class="w-[104px]">
                        <col class="w-[110px]">
                        <col class="w-[110px]">
                        <col class="w-[110px]">
                        <col class="w-[104px]">
                    </colgroup>
                    <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">
                                @if ($canOperateLoan)
                                    <input class="rounded border-slate-300" type="checkbox" data-bulk-payment-toggle aria-label="Seleccionar todas las letras visibles">
                                @endif
                            </th>
                            <th class="px-3 py-3">Letra</th>
                            <th class="px-3 py-3">Fecha</th>
                            <th class="px-3 py-3 text-right">Pagado</th>
                            <th class="px-3 py-3 text-right">Abono a Capital</th>
                            <th class="px-3 py-3 text-right">Interes</th>
                            <th class="px-3 py-3 text-right">Subtotal</th>
                            <th class="px-3 py-3 text-right">Capital</th>
                            <th class="px-3 py-3">Estatus</th>
                            <th class="px-3 py-3 text-right">Pagaré</th>
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
                                $graceLimit = $installment->due_date->copy()->addDays((int) ($loan->delinquency_grace_days ?? 0))->toDateString();
                                $subtotalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);
                                $rowDelinquencyCents = (! $movement && Money::cents($installment->remaining_amount) > 0 && (float) ($loan->delinquency_rate ?? 0) > 0 && $graceLimit < $today)
                                    ? (int) round(Money::cents($installment->contract_amount) * ((float) $loan->delinquency_rate / 100))
                                    : 0;
                                $capitalAdvanceAllowed = $canOperateLoan
                                    && ! $movement
                                    && Money::cents($installment->remaining_amount) > 0
                                    && Money::cents($installment->principal_amount) > 0
                                    && $loan->installments
                                        ->filter(fn ($candidate) => $candidate->number > $installment->number)
                                        ->filter(fn ($candidate) => Money::cents($candidate->remaining_amount) > 0 && ! $candidate->reportedMovement)
                                        ->isEmpty();
                            @endphp
                            <tr class="{{ $isOverdue ? 'bg-red-50/35' : ($next?->id === $installment->id ? 'bg-[#e6f7f4]/40' : '') }}">
                                <td class="px-3 py-2">
                                    @if ($canOperateLoan && Money::cents($installment->remaining_amount) > 0 && ! $movement)
                                        <input class="rounded border-slate-300" type="checkbox" value="{{ $installment->id }}" data-bulk-payment-checkbox @if($isOverdue) data-overdue-payment-checkbox @endif aria-label="Seleccionar letra {{ $installment->number }}">
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-semibold">{{ $installment->number }}</td>
                                <td class="px-3 py-2">{{ $installment->due_date->format('d/m/Y') }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if (Money::cents($installment->remaining_amount) > 0 && ! $movement)
                                        <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid data-capital-advance-allowed="{{ $capitalAdvanceAllowed ? 'true' : 'false' }}">
                                            @csrf
                                            <input name="return_to" type="hidden" value="loan">
                                            <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                            <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                            <input name="operator_surcharge_amount" type="hidden" value="0">
                                            <input name="external_concepts_amount" type="hidden" value="0">
                                            <input name="additional_charge_amount" type="hidden" value="0">
                                            <input name="delinquency_amount" type="hidden" value="{{ Money::decimal($rowDelinquencyCents) }}">
                                            <button class="rounded-md bg-[#0d9488] px-2 py-1 text-xs font-bold text-white" type="submit">Pagado</button>
                                        </form>
                                    @elseif ($movement && $canReverseInstallmentPayment)
                                        <form method="POST" action="{{ route('payments.reverse', $movement) }}">
                                            @csrf
                                            <button class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700" type="submit">Regresar a no pagado</button>
                                        </form>
                                    @else
                                        <span class="text-xs font-semibold text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">{{ Money::mxn($installment->principal_amount) }}</td>
                                <td class="px-3 py-2 text-right">{{ Money::mxn($installment->interest_amount) }}</td>
                                <td class="px-3 py-2 text-right">{{ Money::mxn(Money::decimal($subtotalCents)) }}</td>
                                <td class="px-3 py-2 text-right">{{ Money::mxn($installment->capital_balance) }}</td>
                                <td class="px-3 py-2">
                                    <span class="{{ $statusClass }} rounded px-2 py-1 text-xs font-bold">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold">{{ Money::mxn($installment->contract_amount) }}</td>
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
                        @if (! $movement->affects_investors)
                            <p class="mt-2 inline-flex rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">Sin efectos en inversionistas</p>
                        @endif
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
                            <div>
                                <dt class="text-slate-500">Cargo adicional</dt>
                                <dd class="font-bold">{{ Money::mxn($movement->additional_charge_amount ?? 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Morosidad</dt>
                                <dd class="font-bold">{{ Money::mxn($movement->delinquency_amount ?? 0) }}</dd>
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

    @if ($canReportPayment)
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
                        <option value="advance">{{ ($loan->calculation_method ?? 'regular') === 'interest_only' ? 'Abono a capital vivo' : 'Abono a capital: cuotas completas desde el final' }}</option>
                    </select>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="operated_on">Fecha</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operated_on" name="operated_on" type="date" value="{{ now('America/Merida')->toDateString() }}">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="contract_amount">Subtotal operativo</label>
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
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="additional_charge_amount">Cobro adicional</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="additional_charge_amount" name="additional_charge_amount" type="number" step="0.01" value="0">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700" for="delinquency_amount">Morosidad</label>
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="delinquency_amount" name="delinquency_amount" type="number" step="0.01" value="{{ Money::decimal($nextDelinquencyCents) }}">
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
    @endif

    @if ($canManageLoanDetails)
        <dialog id="freeze-loan-modal" class="w-[min(92vw,520px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <form method="POST" action="{{ route('loans.freeze', $loan) }}">
                @csrf
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-blue-700">Congelar prestamo</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->folio }}</h3>
                </div>
                <div class="px-5 py-4">
                    <label class="text-sm font-semibold text-slate-700" for="frozen_reason">Motivo</label>
                    <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="frozen_reason" name="frozen_reason" rows="4" placeholder="Ej. En proceso legal con abogado" required></textarea>
                    <p class="mt-2 text-xs text-slate-500">Mientras este congelado no se suma a cobranza mensual ni esperado de cortes.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                    <button class="rounded-md bg-blue-700 px-4 py-2 text-sm font-bold text-white" type="submit">Congelar</button>
                </div>
            </form>
        </dialog>

    @endif

    @if ($canViewInvoice)
        <dialog id="loan-invoice-modal" class="w-[min(94vw,760px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Factura</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->folio }}</h3>
            </div>
            <div class="grid max-h-[74vh] gap-5 overflow-y-auto px-5 py-4 {{ $canManageInvoice ? 'lg:grid-cols-[1fr_300px]' : '' }}">
                <div class="space-y-3 text-sm">
                    <div class="rounded-md bg-slate-50 p-4">
                        <p class="text-slate-500">Ubicacion fisica actual</p>
                        <p class="mt-1 text-lg font-bold text-slate-950">{{ $loan->invoice_holder ?: 'Sin registrar' }}</p>
                        @if ($loan->invoiceDocument)
                            <div class="mt-2 flex flex-wrap gap-2">
                                <a class="inline-flex rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" href="{{ route('documents.download', $loan->invoiceDocument) }}">Descargar factura PDF</a>
                                @if ($canManageInvoice)
                                    <form method="POST" action="{{ route('loans.invoice.destroy', $loan) }}" data-confirm-delete data-confirm-title="¿Eliminar factura?" data-confirm-message="Se quitara esta factura del prestamo para poder cargar otra.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="inline-flex rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700" type="submit">Eliminar factura</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                    <h4 class="font-bold text-slate-950">Historial</h4>
                    @forelse ($loan->invoiceMovements->sortByDesc('moved_at') as $movement)
                        <div class="rounded-md border border-slate-100 p-3">
                            <p class="font-semibold text-slate-950">{{ $movement->from_holder ?: 'Inicio' }} → {{ $movement->to_holder }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $movement->moved_at->format('d/m/Y H:i') }} · {{ $movement->movedBy?->name ?? 'Sistema' }}</p>
                            @if ($movement->notes)
                                <p class="mt-2 text-xs text-slate-600">{{ $movement->notes }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-md bg-slate-50 p-4 text-slate-500">Sin movimientos de factura.</p>
                    @endforelse
                </div>
                @if ($canManageInvoice)
                <div class="space-y-4">
                    <form class="space-y-3 rounded-md bg-slate-50 p-4" method="POST" action="{{ route('loans.invoice.store', $loan) }}" enctype="multipart/form-data">
                        @csrf
                        <h4 class="font-bold text-slate-950">Subir factura PDF</h4>
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#e6f7f4] file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#0f766e]" name="file" type="file" accept="application/pdf" required>
                        <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="holder" required>
                            <option value="Recepcion">Recepcion</option>
                            <option value="Caja">Caja</option>
                            <option value="Operador">Operador</option>
                            <option value="En tramite">En tramite</option>
                        </select>
                        <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="2" placeholder="Nota opcional"></textarea>
                        <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar factura</button>
                    </form>
                    <form class="space-y-3 rounded-md bg-slate-50 p-4" method="POST" action="{{ route('loans.invoice.move', $loan) }}">
                        @csrf
                        <h4 class="font-bold text-slate-950">Mover factura fisica</h4>
                        <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="to_holder" required>
                            <option value="Caja">Caja</option>
                            <option value="Recepcion">Recepcion</option>
                            <option value="Operador">Operador</option>
                            <option value="En tramite">En tramite</option>
                        </select>
                        <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="2" placeholder="Motivo o tramite"></textarea>
                        <button class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700">Actualizar ubicacion</button>
                    </form>
                </div>
                @endif
            </div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cerrar</button>
            </div>
        </dialog>
    @endif

    @if ($canManageLoanDetails)
        <dialog id="loan-notes-modal" class="w-[min(92vw,620px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Notas</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->folio }}</h3>
            </div>
            <div class="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4">
                <form class="space-y-3 rounded-md bg-slate-50 p-4" method="POST" action="{{ route('loans.notes.store', $loan) }}">
                    @csrf
                    <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="note" rows="3" placeholder="Escribe una nota del prestamo" required></textarea>
                    <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Agregar nota</button>
                </form>
                <div class="space-y-2">
                    @forelse ($loan->notes->sortByDesc('created_at') as $note)
                        <div class="rounded-md border border-slate-100 p-3 text-sm">
                            <p class="text-slate-700">{{ $note->note }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ $note->created_at->format('d/m/Y H:i') }} · {{ $note->user?->name ?? 'Sistema' }}</p>
                        </div>
                    @empty
                        <p class="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Sin notas registradas.</p>
                    @endforelse
                </div>
            </div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cerrar</button>
            </div>
        </dialog>
    @endif

    @if ($canManageLoanDetails)
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
                <p class="text-sm text-slate-500">La suma de capital debe ser exactamente {{ Money::mxn($loan->capital) }} y la suma de porcentajes debe ser exactamente 100%. Si dejas todos los inversionistas en blanco, el prestamo quedara sin inversionistas asignados.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>Cancelar</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Guardar inversionistas</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($canManageLoanDetails)
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
                                <form method="POST" action="{{ route('documents.destroy', $document) }}" data-confirm-delete data-confirm-title="¿Eliminar este archivo del expediente?" data-confirm-message="Esta accion quitara el archivo del expediente. Si fue un error, tendran que volver a subirlo.">
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
    @endif

    @if ($canSettleLoan && $loan->status === 'active')
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
