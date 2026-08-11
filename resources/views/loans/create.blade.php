<x-layouts.app title="Crear prestamo">
    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('loans.quote-rounded') }}">
        @csrf
        @if ($weeklyCut ?? null)
            <input name="weekly_cut_id" type="hidden" value="{{ $weeklyCut->id }}">
            <div class="mb-5 rounded-md border border-[#99f6e4] bg-[#e6f7f4] px-4 py-3 text-sm text-[#0f766e]">
                Prestamo ligado al corte de {{ $weeklyCut->operator->name }} · {{ $weeklyCut->period_starts_on->format('d/m/Y') }} - {{ $weeklyCut->period_ends_on->format('d/m/Y') }}.
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
                    <div>
                        <label class="text-sm font-semibold text-slate-700">Cliente existente</label>
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="client_id">
                            <option value="">Crear cliente basico</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->first_name }} {{ $client->last_name }} · {{ $client->phone }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" placeholder="Nombre requerido" value="{{ old('first_name') }}">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" placeholder="Apellidos opcional" value="{{ old('last_name') }}">
                    </div>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" placeholder="Celular opcional" value="{{ old('phone') }}">
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="Correo opcional" type="email" value="{{ old('email') }}">
                </section>

                <section class="space-y-4">
                    <h3 class="font-bold text-slate-950">Vehiculo</h3>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="brand" placeholder="Marca opcional" value="{{ old('brand') }}">
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="model" placeholder="Modelo opcional" value="{{ old('model') }}">
                    <div class="grid grid-cols-2 gap-3">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="year" placeholder="Año" type="number" value="{{ old('year') }}">
                        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="plates" placeholder="Placas" value="{{ old('plates') }}">
                    </div>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vin" placeholder="VIN" value="{{ old('vin') }}">
                </section>
            </div>

            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Condiciones</h3>
                <label class="block text-sm font-semibold text-slate-700">Metodo de prestamo
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="calculation_method">
                        <option value="regular" @selected(old('calculation_method', 'regular') === 'regular')>Prestamo regular</option>
                        <option value="rounded" @selected(old('calculation_method') === 'rounded')>Prestamo con redondeo</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Capital requerido
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="capital" placeholder="Ej. 100000" type="number" step="0.01" value="{{ old('capital') }}" required>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Tipo de tasa
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_type" required>
                            <option value="monthly" @selected(old('rate_type', 'monthly') === 'monthly')>Tasa mensual</option>
                            <option value="annual" @selected(old('rate_type') === 'annual')>Tasa anual</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Porcentaje
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_value" placeholder="Ej. 2" type="number" step="0.000001" value="{{ old('rate_value') }}" required>
                    </label>
                </div>
                <p class="-mt-2 text-xs text-slate-500">Captura el porcentaje en numero: para 2% escribe 2; para 24% escribe 24.</p>
                <label class="block text-sm font-semibold text-slate-700">Gtos Admon fijo por pago
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="administration_fee" placeholder="0.00" type="number" step="0.01" value="{{ old('administration_fee', '0') }}">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Fecha de inicio
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ old('start_date', now('America/Merida')->toDateString()) }}" required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Fecha de cobranza
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_payment_date" type="date" value="{{ old('first_payment_date', now('America/Merida')->toDateString()) }}" required>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Fecha real de entrega/desembolso
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="disbursement_delivered_on" type="date" value="{{ old('disbursement_delivered_on', now('America/Merida')->toDateString()) }}">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Nota de entrega
                    <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="disbursement_notes" rows="2" placeholder="Opcional">{{ old('disbursement_notes') }}</textarea>
                </label>
                <label class="block text-sm font-semibold text-slate-700">IVA
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vat_enabled" required>
                        <option value="1" @selected(old('vat_enabled', '1') === '1')>Con IVA</option>
                        <option value="0" @selected(old('vat_enabled') === '0')>Sin IVA</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Calculo de interes
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="interest_calculation_method" required>
                        <option value="fixed_principal" @selected(old('interest_calculation_method', 'fixed_principal') === 'fixed_principal')>Interes fijo sobre capital</option>
                        <option value="outstanding_balance" @selected(old('interest_calculation_method') === 'outstanding_balance')>Interes sobre saldo insoluto</option>
                    </select>
                </label>
                <div class="grid grid-cols-3 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">Meses
                        <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" required>
                            <option value="">Meses</option>
                            @foreach ($terms as $term)
                                <option value="{{ $term }}" @selected((string) old('term_months') === (string) $term)>{{ $term }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="col-span-2 block text-sm font-semibold text-slate-700">Dia de pago
                        <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" placeholder="Dia" type="number" min="1" max="31" value="{{ old('payment_day') }}" required>
                    </label>
                </div>
            </section>
        </div>
        <div class="mt-5">
            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Simular prestamo</button>
        </div>
    </form>
</x-layouts.app>
