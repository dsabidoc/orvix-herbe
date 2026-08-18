@php use App\Support\Money; @endphp

<x-layouts.app title="Simulador de prestamo">
    <style>
        @media print {
            .simulator-form, .simulator-actions { display: none !important; }
            .simulator-print { box-shadow: none !important; border: 0 !important; }
            .simulator-table-wrap { max-height: none !important; overflow: visible !important; }
            @page { size: letter portrait; margin: 10mm; }
        }
    </style>

    <div class="space-y-6">
        <form class="simulator-form rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="GET" action="{{ route('simulator.index') }}">
            <div class="grid gap-4 lg:grid-cols-4 xl:grid-cols-6">
                <div>
                    <label class="text-sm font-semibold text-slate-700">Cliente</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="client_name" value="{{ $input['client_name'] }}" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Metodo</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="calculation_method" data-loan-calculation-method required>
                        <option value="regular" @selected(($input['calculation_method'] ?? 'regular') === 'regular')>Prestamo regular</option>
                        <option value="rounded" @selected(($input['calculation_method'] ?? 'regular') === 'rounded')>Prestamo con redondeo</option>
                        <option value="interest_only" @selected(($input['calculation_method'] ?? 'regular') === 'interest_only')>Prestamo de solo interes</option>
                    </select>
                    <p class="mt-1 hidden text-xs text-slate-500" data-interest-only-help>Sin plazo determinado: se proyectan mensualidades de interes sobre el capital vivo, y los abonos reducen intereses futuros.</p>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" required>
                        <option value="">Seleccionar</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected((string) $input['operator_id'] === (string) $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Capital</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="capital" type="number" step="0.01" value="{{ $input['capital'] }}" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Tipo de tasa</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_type" required>
                        <option value="monthly" @selected($input['rate_type'] === 'monthly')>Mensual</option>
                        <option value="annual" @selected($input['rate_type'] === 'annual')>Anual</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Tasa (%)</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_value" type="number" step="0.000001" value="{{ $input['rate_value'] }}" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Gtos Admon</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="administration_fee" type="number" step="0.01" value="{{ $input['administration_fee'] ?? '0' }}">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">IVA</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vat_enabled" required>
                        <option value="1" @selected(($input['vat_enabled'] ?? '1') === '1')>Con IVA</option>
                        <option value="0" @selected(($input['vat_enabled'] ?? '1') === '0')>Sin IVA</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Calculo de interes</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="interest_calculation_method" required>
                        <option value="fixed_principal" @selected($input['interest_calculation_method'] === 'fixed_principal')>Fijo sobre capital</option>
                        <option value="outstanding_balance" @selected($input['interest_calculation_method'] === 'outstanding_balance')>Saldo insoluto</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Comision apertura</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="opening_fee_type">
                        <option value="none" @selected($input['opening_fee_type'] === 'none')>Sin comision</option>
                        <option value="percent" @selected($input['opening_fee_type'] === 'percent')>Porcentaje</option>
                        <option value="fixed" @selected($input['opening_fee_type'] === 'fixed')>Monto fijo</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Valor comision</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="opening_fee_value" type="number" step="0.01" value="{{ $input['opening_fee_value'] }}">
                </div>
                <div data-term-months-wrapper>
                    <label class="text-sm font-semibold text-slate-700">Meses</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" type="number" value="{{ $input['term_months'] }}" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Inicio</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ $input['start_date'] }}" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Primer pago</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_payment_date" type="date" value="{{ $input['first_payment_date'] ?? $input['start_date'] }}">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Dia pago</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" type="number" min="1" max="31" value="{{ $input['payment_day'] }}" required>
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <button class="rounded-md bg-[#0d9488] px-6 py-2 text-sm font-bold text-white">Calcular</button>
            </div>
        </form>

        <section class="simulator-print rounded-lg border border-slate-200 bg-white shadow-sm">
            @if ($roundedQuote)
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Simulacion con redondeo</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ $input['client_name'] }}</h3>
                    <p class="mt-1 text-sm text-slate-500">Ambas opciones tienen el mismo total; cambia el primer pago y los pagos restantes.</p>
                </div>
                <div class="grid gap-4 p-5 xl:grid-cols-2">
                    @foreach ($roundedQuote['options'] as $option)
                        <section class="rounded-lg border border-slate-200">
                            <div class="border-b border-slate-200 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#0f766e]">{{ $option['name'] }}</p>
                                <h4 class="mt-1 font-bold text-slate-950">{{ $option['description'] }}</h4>
                                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Primer pago</dt><dd class="font-bold">{{ Money::mxn($option['first_payment']) }}</dd></div>
                                    <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Restantes</dt><dd class="font-bold">{{ $option['remaining_payments'] }} de {{ Money::mxn($option['regular_payment']) }}</dd></div>
                                    <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Interes total</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($roundedQuote['input']['interest_total_cents'])) }}</dd></div>
                                    <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Total</dt><dd class="font-bold">{{ Money::mxn($option['total']) }}</dd></div>
                                </dl>
                            </div>
                            <div class="max-h-[420px] overflow-auto">
                                <table class="w-full min-w-[760px] text-left text-sm">
                                    <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Pago</th><th class="px-4 py-3">Vence</th><th class="px-4 py-3 text-right">Capital</th><th class="px-4 py-3 text-right">Interes</th><th class="px-4 py-3 text-right">Cobranza</th><th class="px-4 py-3 text-right">Pagaré</th><th class="px-4 py-3 text-right">Capital vivo</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($option['installments'] as $installment)
                                            <tr><td class="px-4 py-3">{{ $installment['number'] }}</td><td class="px-4 py-3">{{ \Carbon\CarbonImmutable::parse($installment['due_date'])->format('d/m/Y') }}</td><td class="px-4 py-3 text-right">{{ Money::mxn($installment['principal']) }}</td><td class="px-4 py-3 text-right">{{ Money::mxn($installment['interest']) }}</td><td class="px-4 py-3 text-right">{{ Money::mxn($installment['administration_fee']) }}</td><td class="px-4 py-3 text-right font-semibold">{{ Money::mxn($installment['amount']) }}</td><td class="px-4 py-3 text-right">{{ Money::mxn($installment['balance']) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endforeach
                </div>
            @elseif ($schedule)
                <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Simulacion de prestamo</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">{{ $input['client_name'] }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $interestCalculationLabel }} · {{ ucfirst($input['rate_type']) }} {{ number_format((float) $input['rate_value'], 2) }}% · {{ ($input['vat_enabled'] ?? '1') === '1' ? 'Con IVA' : 'Sin IVA' }}</p>
                        @if (($input['calculation_method'] ?? 'regular') === 'interest_only')
                            <p class="mt-1 text-sm text-slate-500">Sin plazo determinado; la tabla es una proyeccion y se recalcula cuando se abona a capital.</p>
                        @endif
                    </div>
                    <button class="simulator-actions rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" type="button" onclick="window.print()">Descargar / imprimir</button>
                </div>
                <dl class="grid gap-3 border-b border-slate-200 p-5 md:grid-cols-5">
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-sm text-slate-500">Capital</dt>
                        <dd class="mt-1 font-bold">{{ Money::mxn($schedule->capital()) }}</dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-sm text-slate-500">Gtos Admon</dt>
                        <dd class="mt-1 font-bold">{{ Money::mxn($input['administration_fee'] ?? '0') }} fijo mensual</dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-sm text-slate-500">{{ ($input['vat_enabled'] ?? '1') === '1' ? 'Interes + IVA' : 'Interes' }}</dt>
                        <dd class="mt-1 font-bold">{{ Money::mxn(\App\Domain\Loans\LoanSchedule::formatCents($schedule->interestCents)) }}</dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-sm text-slate-500">Comision</dt>
                        <dd class="mt-1 font-bold">{{ Money::mxn($openingFeeAmount) }}</dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-sm text-slate-500">{{ ($input['calculation_method'] ?? 'regular') === 'interest_only' ? 'Proyeccion' : 'Contrato' }}</dt>
                        <dd class="mt-1 font-bold">{{ Money::mxn($contractTotalWithFee) }}</dd>
                    </div>
                </dl>
                <div class="simulator-table-wrap max-h-[620px] overflow-auto">
                    <table class="w-full min-w-[1040px] text-left text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                            <tr><th class="px-5 py-3">Letras</th><th class="px-5 py-3">Vence</th><th class="px-5 py-3 text-right">Mensualidad</th><th class="px-5 py-3 text-right">Amortizacion</th><th class="px-5 py-3 text-right">Gtos Admon</th><th class="px-5 py-3 text-right">Intereses</th><th class="px-5 py-3 text-right">Iva Intereses</th><th class="px-5 py-3 text-right">Capital Vivo</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($displaySchedule as $installment)
                                <tr><td class="px-5 py-3">{{ $installment['number'] }}</td><td class="px-5 py-3">{{ \Carbon\CarbonImmutable::parse($installment['due_date'])->format('d/m/Y') }}</td><td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($installment['amount']) }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['principal'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['administration_fee'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['interest'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['interest_vat'] ?? '0.00') }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($installment['balance'] ?? '0.00') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="p-5 text-sm text-slate-500">Completa los datos para simular el calendario.</p>
            @endif
        </section>
    </div>
</x-layouts.app>
