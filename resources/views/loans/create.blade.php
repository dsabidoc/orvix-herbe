<x-layouts.app title="Crear prestamo">
    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('loans.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid gap-6 xl:grid-cols-3">
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
                <div>
                    <label class="text-sm font-semibold text-slate-700">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" required>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected((string) old('operator_id') === (string) $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
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
                <label class="block text-sm font-semibold text-slate-700">Expediente</label>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="documents[]" type="file" multiple>
            </section>

            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Condiciones</h3>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="capital" placeholder="Capital requerido" type="number" step="0.01" value="{{ old('capital') }}" required>
                <div class="grid grid-cols-2 gap-3">
                    <select class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_type" required>
                        <option value="monthly" @selected(old('rate_type', 'monthly') === 'monthly')>Tasa mensual</option>
                        <option value="annual" @selected(old('rate_type') === 'annual')>Tasa anual</option>
                    </select>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="rate_value" placeholder="Porcentaje, ej. 2" type="number" step="0.000001" value="{{ old('rate_value') }}" required>
                </div>
                <p class="-mt-2 text-xs text-slate-500">Captura el porcentaje en numero: para 2% escribe 2; para 24% escribe 24.</p>
                <div>
                    <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="administration_fee" placeholder="Gtos Admon" type="number" step="0.01" value="{{ old('administration_fee', '0') }}">
                </div>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="vat_enabled" required>
                    <option value="1" @selected(old('vat_enabled', '1') === '1')>Con IVA</option>
                    <option value="0" @selected(old('vat_enabled') === '0')>Sin IVA</option>
                </select>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="interest_calculation_method" required>
                    <option value="fixed_principal" @selected(old('interest_calculation_method', 'fixed_principal') === 'fixed_principal')>Interes fijo sobre capital</option>
                    <option value="outstanding_balance" @selected(old('interest_calculation_method') === 'outstanding_balance')>Interes sobre saldo insoluto</option>
                </select>
                <div class="grid grid-cols-3 gap-3">
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" placeholder="Meses" type="number" value="{{ old('term_months') }}" required>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" placeholder="Dia" type="number" min="1" max="31" value="{{ old('payment_day') }}" required>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="start_date" type="date" value="{{ old('start_date', now('America/Merida')->toDateString()) }}" required>
                </div>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Crear prestamo</button>
            </section>
        </div>
    </form>
</x-layouts.app>
