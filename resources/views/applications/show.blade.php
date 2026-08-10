@php
    use App\Support\Money;
    use App\Support\StatusLabels;

    $vatEnabled = filter_var($conditions['vat_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
@endphp

<x-layouts.app title="Solicitud {{ $application->folio }} · {{ $application->client->first_name }} {{ $application->client->last_name }}">
    <div class="no-print mb-4 flex justify-end">
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" onclick="window.print()">Descargar / imprimir</button>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <section class="print-sheet rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5">
                <p class="no-print text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Detalle de la solicitud</p>
                <p class="print-only text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Solicitud de Prestamo</p>
                <h3 class="mt-2 text-xl font-bold text-slate-950">{{ $application->client->first_name }} {{ $application->client->last_name }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $application->folio }} · {{ $application->client->phone }} · Operador {{ $application->operator?->name }}</p>
            </div>
            <div class="grid gap-4 border-b border-slate-200 px-5 py-4 md:grid-cols-2">
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Capital</p><p class="text-lg font-bold text-slate-950">{{ Money::mxn($conditions['capital']) }}</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">{{ $rateLabel }}</p><p class="text-lg font-bold text-slate-950">{{ $rateValue }}</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Gtos Admon</p><p class="text-lg font-bold text-slate-950">{{ Money::mxn($conditions['administration_fee'] ?? '0.00') }} mensual</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Plazo</p><p class="text-lg font-bold text-slate-950">{{ $conditions['term_months'] }} meses</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Contrato</p><p class="text-lg font-bold text-slate-950">{{ Money::mxn($contractTotalWithFee) }}</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Calculo de interes</p><p class="text-lg font-bold text-slate-950">{{ $interestCalculationLabel }}</p></div>
                <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">IVA</p><p class="text-lg font-bold text-slate-950">{{ $vatEnabled ? 'Con IVA' : 'Sin IVA' }}</p></div>
                @if ($conditions['opening_fee_type'] !== 'none')
                    <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Comision apertura</p><p class="text-lg font-bold text-slate-950">{{ $openingFeeLabel }}</p></div>
                    <div class="rounded-md bg-slate-50 p-3"><p class="text-sm text-slate-500">Comision calculada</p><p class="text-lg font-bold text-slate-950">{{ Money::mxn($openingFeeAmount) }}</p></div>
                @endif
            </div>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Letras</th><th class="px-5 py-3">Vence</th><th class="px-5 py-3 text-right">Mensualidad</th><th class="px-5 py-3 text-right">Amortizacion</th><th class="px-5 py-3 text-right">Gtos Admon</th><th class="px-5 py-3 text-right">Intereses</th><th class="px-5 py-3 text-right">Iva Intereses</th><th class="px-5 py-3 text-right">Capital Vivo</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($displaySchedule as $installment)
                        <tr><td class="px-5 py-3">{{ $installment['number'] }}</td><td class="px-5 py-3">{{ \Carbon\CarbonImmutable::parse($installment['due_date'])->format('d/m/Y') }}</td><td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($installment['amount']) }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['principal'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['administration_fee'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['interest'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['interest_vat'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['balance'] ?? '0.00') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <aside class="no-print space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Datos de solicitud</h3>
                <p class="mt-1 text-sm text-slate-500">Estos datos son los que envio el operador y no se editan.</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Monto solicitado</dt>
                        <dd class="font-bold">{{ Money::mxn($application->requested_capital) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Tiempo solicitado</dt>
                        <dd class="font-bold">{{ $application->term_months }} meses</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Dia de pago</dt>
                        <dd class="font-bold">{{ $application->payment_day }}</dd>
                    </div>
                </dl>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Estado</h3>
                <p class="mt-2 text-sm font-semibold text-[#0f766e]">{{ StatusLabels::application($application->status) }}</p>
                @if ($application->rejected_reason)
                    <p class="mt-2 text-sm text-red-700">{{ $application->rejected_reason }}</p>
                @endif
            </section>
            @can('applications.authorize')
                @if (! in_array($application->status, ['approved', 'started'], true))
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Autorizar condiciones</h3>
                        <form class="mt-4 space-y-3" method="POST">
                            @csrf
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Monto autorizado</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="capital" type="number" step="0.01" value="{{ $conditions['capital'] }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Tiempo o periodo</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" type="number" value="{{ $conditions['term_months'] }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Dia de pago</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" type="number" min="1" max="31" value="{{ $conditions['payment_day'] }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Fecha de inicio</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ $conditions['start_date'] }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Gtos Admon fijo mensual</label>
                                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="administration_fee" type="number" step="0.01" value="{{ $conditions['administration_fee'] ?? '0.00' }}">
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">IVA</label>
                                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vat_enabled">
                                    <option value="1" @selected($vatEnabled)>Con IVA</option>
                                    <option value="0" @selected(! $vatEnabled)>Sin IVA</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Tipo de tasa</label>
                                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_type">
                                        <option value="monthly" @selected($conditions['rate_type'] === 'monthly')>Mensual</option>
                                        <option value="annual" @selected($conditions['rate_type'] === 'annual')>Anual</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Tasa (%)</label>
                                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_value" type="number" step="0.000001" value="{{ $conditions['rate_value'] }}">
                                </div>
                            </div>
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Calculo de interes</label>
                                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="interest_calculation_method">
                                    <option value="fixed_principal" @selected(($conditions['interest_calculation_method'] ?? 'fixed_principal') === 'fixed_principal')>Fijo sobre capital</option>
                                    <option value="outstanding_balance" @selected(($conditions['interest_calculation_method'] ?? 'fixed_principal') === 'outstanding_balance')>Saldo insoluto</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Comision apertura</label>
                                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="opening_fee_type">
                                        <option value="none" @selected($conditions['opening_fee_type'] === 'none')>Sin comision</option>
                                        <option value="percent" @selected($conditions['opening_fee_type'] === 'percent')>Porcentaje</option>
                                        <option value="fixed" @selected($conditions['opening_fee_type'] === 'fixed')>Monto fijo</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Valor comision</label>
                                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="opening_fee_value" type="number" step="0.01" value="{{ $conditions['opening_fee_value'] }}">
                                </div>
                            </div>
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                Comision calculada: <strong>{{ Money::mxn($openingFeeAmount) }}</strong>
                            </div>
                            <button class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" formaction="{{ route('applications.simulate', $application) }}" type="submit">Simular condiciones</button>
                            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" formaction="{{ route('applications.approve', $application) }}" type="submit">Aprobar y enviar al operador</button>
                        </form>
                        <form class="mt-3 space-y-3" method="POST" action="{{ route('applications.reject', $application) }}">
                            @csrf
                            <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rejected_reason" placeholder="Motivo de rechazo"></textarea>
                            <button class="w-full rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white">Rechazar</button>
                        </form>
                    </section>
                @endif
            @endcan
            @if ($application->status === 'approved')
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-bold text-slate-950">Comenzar credito</h3>
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('applications.start', $application) }}">
                        @csrf
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ $conditions['start_date'] }}">
                        <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white">Comenzar</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
