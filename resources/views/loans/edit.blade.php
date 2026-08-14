@php
    $guarantorPrefill = $guarantors->values()->map(fn ($loan) => [
        'display' => trim($loan->guarantor_name).($loan->guarantor_phone ? ' · '.$loan->guarantor_phone : ''),
        'name' => $loan->guarantor_name,
        'address' => $loan->guarantor_address,
        'phone' => $loan->guarantor_phone,
    ]);
@endphp

<x-layouts.app title="Editar prestamo {{ $loan->folio }}">
    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('loans.update', $loan) }}">
        @csrf
        @method('PUT')

        @unless ($canEditConditions)
            <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Este prestamo ya tiene cobros registrados. Puedes editar cliente, operador y vehiculo; las condiciones financieras quedan bloqueadas para conservar la cobranza historica.
            </div>
        @endunless

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Cliente</h3>
                <div class="grid grid-cols-2 gap-3">
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" placeholder="Nombre requerido" value="{{ old('first_name', $loan->client->first_name) }}" required>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" placeholder="Apellidos opcional" value="{{ old('last_name', $loan->client->last_name) }}">
                </div>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" placeholder="Celular opcional (10 digitos)" value="{{ old('phone', $loan->client->phone) }}" inputmode="numeric" minlength="10" maxlength="10" pattern="[0-9]{10}">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="Correo opcional" type="email" value="{{ old('email', $loan->client->email) }}">
                <div class="border-t border-slate-100 pt-4">
                    <h4 class="font-bold text-slate-950">Aval</h4>
                    <div class="mt-3 space-y-3">
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_name" placeholder="Nombre completo aval o busca existente" value="{{ old('guarantor_name', $loan->guarantor_name) }}" list="loan-guarantor-options" data-guarantor-search data-guarantor-field="name" data-guarantors='@json($guarantorPrefill)' autocomplete="off">
                        <datalist id="loan-guarantor-options">
                            @foreach ($guarantorPrefill as $guarantor)
                                <option value="{{ $guarantor['display'] }}"></option>
                            @endforeach
                        </datalist>
                        <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_address" rows="2" placeholder="Direccion aval" data-guarantor-field="address">{{ old('guarantor_address', $loan->guarantor_address) }}</textarea>
                        <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="guarantor_phone" placeholder="Celular aval opcional (10 digitos)" value="{{ old('guarantor_phone', $loan->guarantor_phone) }}" inputmode="numeric" minlength="10" maxlength="10" pattern="[0-9]{10}" data-guarantor-field="phone">
                    </div>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" required>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected((string) old('operator_id', $loan->operator_id) === (string) $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
            </section>

            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Vehiculo</h3>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="brand" placeholder="Marca opcional" value="{{ old('brand', $loan->vehicle?->brand) }}">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="model" placeholder="Modelo opcional" value="{{ old('model', $loan->vehicle?->model) }}">
                <div class="grid grid-cols-2 gap-3">
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="year" placeholder="Año" type="number" value="{{ old('year', $loan->vehicle?->year) }}">
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="plates" placeholder="Placas" value="{{ old('plates', $loan->vehicle?->plates) }}">
                </div>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase" name="vin" placeholder="VIN de 17 caracteres" minlength="17" maxlength="17" value="{{ old('vin', $loan->vehicle?->vin) }}">
            </section>

            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Condiciones</h3>
                <label class="block text-sm font-semibold text-slate-700">Capital requerido
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="capital" placeholder="Capital requerido" type="number" step="0.01" value="{{ old('capital', $loan->capital) }}" @disabled(! $canEditConditions) required>
                </label>
                @php
                    $administrationFeeDisplay = old('administration_fee', $loan->administration_fee ?? '0.00');
                    $vatEnabledValue = old('vat_enabled', $loan->vat_enabled ? '1' : '0');
                    $rateType = old('rate_type', 'monthly');
                    $rateValue = old('rate_value', number_format(((float) $loan->monthly_rate) * 100, 6, '.', ''));
                @endphp
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Tipo de tasa
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="rate_type" @disabled(! $canEditConditions) required>
                            <option value="monthly" @selected($rateType === 'monthly')>Tasa mensual</option>
                            <option value="annual" @selected($rateType === 'annual')>Tasa anual</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Porcentaje
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="rate_value" placeholder="Ej. 2" type="number" step="0.000001" value="{{ $rateValue }}" @disabled(! $canEditConditions) required>
                    </label>
                </div>
                <p class="-mt-2 text-xs text-slate-500">Captura el porcentaje en numero: para 2% escribe 2; para 24% escribe 24.</p>
                <div>
                    <label class="block text-sm font-semibold text-slate-700">Gtos Admon fijo por pago
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="administration_fee" placeholder="0.00" type="number" step="0.01" value="{{ $administrationFeeDisplay }}" @disabled(! $canEditConditions)>
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Morosidad %
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="delinquency_rate" placeholder="Ej. 6" type="number" step="0.0001" min="0" max="100" value="{{ old('delinquency_rate', $loan->delinquency_rate ?? '0') }}">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Dias gracia
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="delinquency_grace_days" placeholder="Ej. 5" type="number" min="0" max="365" value="{{ old('delinquency_grace_days', $loan->delinquency_grace_days ?? 0) }}">
                    </label>
                </div>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="vat_enabled" @disabled(! $canEditConditions) required>
                    <option value="1" @selected($vatEnabledValue === '1')>Con IVA</option>
                    <option value="0" @selected($vatEnabledValue === '0')>Sin IVA</option>
                </select>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="interest_calculation_method" @disabled(! $canEditConditions) required>
                    <option value="fixed_principal" @selected(old('interest_calculation_method', $loan->interest_calculation_method) === 'fixed_principal')>Interes fijo sobre capital</option>
                    <option value="outstanding_balance" @selected(old('interest_calculation_method', $loan->interest_calculation_method) === 'outstanding_balance')>Interes sobre saldo insoluto</option>
                </select>
                <div class="grid grid-cols-3 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Meses
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="term_months" placeholder="Meses" type="number" value="{{ old('term_months', $loan->term_months) }}" @disabled(! $canEditConditions) required>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Dia de pago
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="payment_day" placeholder="Dia" type="number" min="1" max="31" value="{{ old('payment_day', $loan->payment_day) }}" data-sync-payment-day-target @disabled(! $canEditConditions) required>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Fecha de compra
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="start_date" type="date" value="{{ old('start_date', $loan->start_date->toDateString()) }}" @disabled(! $canEditConditions) required>
                    </label>
                </div>
                <label class="block text-sm font-semibold text-slate-700">Fecha de vencimiento
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500" name="first_payment_date" type="date" value="{{ old('first_payment_date', optional($loan->first_payment_date ?? $loan->start_date)->toDateString()) }}" data-sync-payment-day-source @disabled(! $canEditConditions) required>
                </label>

                @unless ($canEditConditions)
                    <input name="capital" type="hidden" value="{{ $loan->capital }}">
                    <input name="rate_type" type="hidden" value="monthly">
                    <input name="rate_value" type="hidden" value="{{ number_format(((float) $loan->monthly_rate) * 100, 6, '.', '') }}">
                    <input name="administration_fee" type="hidden" value="{{ $administrationFeeDisplay }}">
                    <input name="vat_enabled" type="hidden" value="{{ $loan->vat_enabled ? '1' : '0' }}">
                    <input name="interest_calculation_method" type="hidden" value="{{ $loan->interest_calculation_method }}">
                    <input name="term_months" type="hidden" value="{{ $loan->term_months }}">
                    <input name="payment_day" type="hidden" value="{{ $loan->payment_day }}">
                    <input name="start_date" type="hidden" value="{{ $loan->start_date->toDateString() }}">
                    <input name="first_payment_date" type="hidden" value="{{ optional($loan->first_payment_date ?? $loan->start_date)->toDateString() }}">
                @endunless

                <div class="flex gap-2">
                    <a class="flex-1 rounded-md border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700" href="{{ route('loans.show', $loan) }}">Cancelar</a>
                    <button class="flex-1 rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar cambios</button>
                </div>
            </section>
        </div>
    </form>
</x-layouts.app>
