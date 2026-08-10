@php
    $clientPayload = $clients->mapWithKeys(fn ($client) => [
        $client->id => [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'phone' => $client->phone,
            'email' => $client->email,
            'operator_id' => $client->operator_id,
        ],
    ]);
@endphp

<x-layouts.app title="Nueva solicitud">
    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('applications.store') }}" data-submit-once>
        @csrf
        <div class="grid gap-6 xl:grid-cols-3">
            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Cliente</h3>
                <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="client_id" data-client-prefill data-clients='@json($clientPayload)'>
                    <option value="">Cliente nuevo</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->first_name }} {{ $client->last_name }} · {{ $client->phone }}</option>
                    @endforeach
                </select>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" placeholder="Nombre" data-client-field="first_name">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" placeholder="Apellidos" data-client-field="last_name">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" placeholder="Celular" data-client-field="phone">
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" placeholder="Correo" type="email" data-client-field="email">
            </section>
            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Solicitud</h3>
                @unless (auth()->user()->hasRole('operador-cartera'))
                    <select class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id" data-client-field="operator_id">
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>
                @endunless
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="requested_capital" placeholder="Monto solicitado" type="number" step="0.01" required>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" placeholder="Meses" type="number" required>
                <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="payment_day" placeholder="Dia de pago" type="number" min="1" max="31" required>
            </section>
            <section class="space-y-4">
                <h3 class="font-bold text-slate-950">Notas</h3>
                <textarea class="h-44 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes"></textarea>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Enviar solicitud</button>
            </section>
        </div>
    </form>
</x-layouts.app>
