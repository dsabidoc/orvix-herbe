@php
    $defaultStartDate = old('start_date', now('America/Merida')->toDateString());
    $defaultFirstPaymentDate = old('first_payment_date', \Carbon\CarbonImmutable::parse($defaultStartDate)->addMonthNoOverflow()->toDateString());
    $defaultDisbursementDate = old('disbursement_delivered_on', $defaultStartDate);
    $defaultPaymentDay = old('payment_day', \Carbon\CarbonImmutable::parse($defaultFirstPaymentDate)->day);
    $clientPrefill = $clients->mapWithKeys(fn ($client) => [
        $client->id => [
            'display' => trim($client->first_name.' '.$client->last_name).($client->phone ? ' · '.$client->phone : ''),
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'phone' => $client->phone,
            'email' => $client->email,
        ],
    ]);
    $guarantorPrefill = $guarantors->values()->map(fn ($loan) => [
        'display' => trim($loan->guarantor_name).($loan->guarantor_phone ? ' · '.$loan->guarantor_phone : ''),
        'name' => $loan->guarantor_name,
        'address' => $loan->guarantor_address,
        'phone' => $loan->guarantor_phone,
    ]);
@endphp

<x-layouts.app title="Crear prestamo">
    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('loans.quote-rounded') }}" enctype="multipart/form-data">
        @csrf
        @if ($weeklyCut ?? null)
            <input name="weekly_cut_id" type="hidden" value="{{ $weeklyCut->id }}">
            <div class="mb-5 rounded-md border border-[#99f6e4] bg-[#e6f7f4] px-4 py-3 text-sm text-[#0f766e]">
                Prestamo ligado al corte de {{ $weeklyCut->operator->name }} · {{ $weeklyCut->period_starts_on->format('d/m/Y') }}.
            </div>
        @endif
        <div class="grid gap-8 xl:grid-cols-2">
            <div class="space-y-6">
                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Operador</h3>
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Operador asignado</label>
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" required>
                            @foreach ($operators as $operator)
                                <option value="{{ $operator->id }}" @selected((string) old('operator_id', $selectedOperatorId ?? '') === (string) $operator->id)>{{ $operator->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Cliente</h3>
                    <input name="client_id" type="hidden" value="{{ old('client_id') }}" data-client-id>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm font-semibold text-slate-700">Nombre / buscar cliente
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" placeholder="Empieza a escribir nombre o celular" value="{{ old('first_name') }}" list="loan-client-options" data-client-search data-client-field="first_name" data-clients='@json($clientPrefill)' autocomplete="off">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Apellidos
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" placeholder="Apellidos opcional" value="{{ old('last_name') }}" data-client-field="last_name">
                        </label>
                    </div>
                    <datalist id="loan-client-options">
                        @foreach ($clientPrefill as $client)
                            <option value="{{ $client['display'] }}"></option>
                        @endforeach
                    </datalist>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" placeholder="Celular opcional (10 digitos)" value="{{ old('phone') }}" inputmode="numeric" minlength="10" maxlength="10" pattern="[0-9]{10}" data-client-field="phone">
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="Correo opcional" type="email" value="{{ old('email') }}" data-client-field="email">
                </section>

                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Vehiculo</h3>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="brand" placeholder="Marca opcional" value="{{ old('brand') }}">
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="model" placeholder="Modelo opcional" value="{{ old('model') }}">
                    <div class="grid grid-cols-2 gap-3">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="year" placeholder="Año" type="number" inputmode="numeric" value="{{ old('year') }}">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="plates" placeholder="Placas" value="{{ old('plates') }}">
                    </div>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase" name="vin" placeholder="VIN de 17 caracteres" minlength="17" maxlength="17" value="{{ old('vin') }}">
                </section>

                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Aval</h3>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_name" placeholder="Nombre completo aval o busca existente" value="{{ old('guarantor_name') }}" list="loan-guarantor-options" data-guarantor-search data-guarantor-field="name" data-guarantors='@json($guarantorPrefill)' autocomplete="off">
                    <datalist id="loan-guarantor-options">
                        @foreach ($guarantorPrefill as $guarantor)
                            <option value="{{ $guarantor['display'] }}"></option>
                        @endforeach
                    </datalist>
                    <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_address" rows="2" placeholder="Direccion aval" data-guarantor-field="address">{{ old('guarantor_address') }}</textarea>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_phone" placeholder="Celular aval opcional (10 digitos)" value="{{ old('guarantor_phone') }}" inputmode="numeric" minlength="10" maxlength="10" pattern="[0-9]{10}" data-guarantor-field="phone">
                </section>

                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Factura</h3>
                    @if (old('invoice_temp_path'))
                        <input name="invoice_temp_path" type="hidden" value="{{ old('invoice_temp_path') }}">
                        <input name="invoice_original_name" type="hidden" value="{{ old('invoice_original_name') }}">
                        <input name="invoice_mime_type" type="hidden" value="{{ old('invoice_mime_type') }}">
                        <input name="invoice_size" type="hidden" value="{{ old('invoice_size') }}">
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">Factura cargada en vista previa: {{ old('invoice_original_name', 'Factura PDF') }}</p>
                    @endif
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#e6f7f4] file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#0f766e]" name="invoice_file" type="file" accept="application/pdf">
                    <label class="block text-sm font-semibold text-slate-700">Ubicacion fisica inicial
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="invoice_holder">
                            <option value="Recepcion" @selected(old('invoice_holder', 'Recepcion') === 'Recepcion')>Recepcion</option>
                            <option value="Caja" @selected(old('invoice_holder') === 'Caja')>Caja</option>
                            <option value="Operador" @selected(old('invoice_holder') === 'Operador')>Operador</option>
                            <option value="En tramite" @selected(old('invoice_holder') === 'En tramite')>En tramite</option>
                        </select>
                    </label>
                    <p class="-mt-2 text-xs text-slate-500">Opcional, PDF menor a 100 MB.</p>
                </section>
            </div>

            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Condiciones</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block text-sm font-semibold text-slate-700">Capital requerido
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="capital" placeholder="Ej. 100000" type="number" step="0.01" value="{{ old('capital') }}" required>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700" data-term-months-wrapper>Meses
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" required>
                            <option value="">Meses</option>
                            @foreach ($terms as $term)
                                <option value="{{ $term }}" @selected((string) old('term_months') === (string) $term)>{{ $term }}</option>
                            @endforeach
                        </select>
                        <input name="term_months" type="hidden" value="1" data-interest-only-term-hidden disabled>
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Tipo de tasa
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_type" required>
                            <option value="monthly" @selected(old('rate_type', 'monthly') === 'monthly')>Tasa mensual</option>
                            <option value="annual" @selected(old('rate_type') === 'annual')>Tasa anual</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Porcentaje
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_value" placeholder="Ej. 2" type="number" step="0.000001" value="{{ old('rate_value', '2') }}" required>
                    </label>
                </div>
                <p class="-mt-2 text-xs text-slate-500">Captura el porcentaje en numero: para 2% escribe 2; para 24% escribe 24.</p>
                <label class="block text-sm font-semibold text-slate-700">Gtos Admon fijo por pago
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="administration_fee" placeholder="0.00" type="number" step="0.01" value="{{ old('administration_fee', '0') }}">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Morosidad %
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="delinquency_rate" placeholder="Ej. 10" type="number" step="0.0001" min="0" max="100" value="{{ old('delinquency_rate', '0') }}">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Dias gracia
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="delinquency_grace_days" placeholder="Ej. 5" type="number" min="0" max="365" value="{{ old('delinquency_grace_days', '0') }}">
                    </label>
                </div>
                <label class="block text-sm font-semibold text-slate-700">Fecha de compra
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ $defaultStartDate }}" data-loan-purchase-date required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Fecha de vencimiento
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_payment_date" type="date" value="{{ $defaultFirstPaymentDate }}" data-sync-payment-day-source required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Fecha real de entrega/desembolso
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="disbursement_delivered_on" type="date" value="{{ $defaultDisbursementDate }}">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Nota de entrega
                    <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="disbursement_notes" rows="2" placeholder="Opcional">{{ old('disbursement_notes') }}</textarea>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Metodo de prestamo
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="calculation_method" data-loan-calculation-method>
                        <option value="rounded" @selected(old('calculation_method', 'rounded') === 'rounded')>Prestamo con redondeo</option>
                        <option value="regular" @selected(old('calculation_method') === 'regular')>Prestamo regular</option>
                        <option value="interest_only" @selected(old('calculation_method') === 'interest_only')>Prestamo de solo interes</option>
                    </select>
                </label>
                <p class="-mt-2 hidden rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-800" data-interest-only-help>
                    Sin plazo determinado: el sistema genera intereses mensuales solo hasta el mes actual. Cada nuevo mes se agregara su interes pendiente hasta que se liquide todo el capital.
                </p>
                <label class="block text-sm font-semibold text-slate-700">IVA
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vat_enabled" required>
                        <option value="0" @selected(old('vat_enabled', '0') === '0')>Sin IVA</option>
                        <option value="1" @selected(old('vat_enabled') === '1')>Con IVA</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Calculo de interes
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="interest_calculation_method" required>
                        <option value="fixed_principal" @selected(old('interest_calculation_method', 'fixed_principal') === 'fixed_principal')>Interes fijo sobre capital</option>
                        <option value="outstanding_balance" @selected(old('interest_calculation_method') === 'outstanding_balance')>Interes sobre saldo insoluto</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Dia de pago
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" placeholder="Dia" type="number" min="1" max="31" value="{{ $defaultPaymentDay }}" data-sync-payment-day-target required>
                </label>
            </section>
        </div>
        <div class="mt-5">
            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Simular prestamo</button>
        </div>
    </form>
</x-layouts.app>
