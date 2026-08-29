@php
    use App\Support\Money;
    use App\Support\StatusLabels;
@endphp

<x-layouts.app title="{{ $client->first_name }} {{ $client->last_name }}">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Perfil del cliente</p>
            <p class="mt-1 text-sm text-slate-600">{{ $client->phone }} {{ $client->email ? '· '.$client->email : '' }}</p>
        </div>
        @can('clients.manage')
            <a class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50" href="{{ route('clients.edit', $client) }}">Editar datos</a>
        @endcan
    </div>
    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="font-bold text-slate-950">Prestamos del cliente</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $client->phone }} {{ $client->email ? '· '.$client->email : '' }} · {{ $client->operator?->name ?? 'Sin operador' }}</p>
            </div>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Folio</th>
                        <th class="px-5 py-3">Vehiculo</th>
                        <th class="px-5 py-3 text-right">Capital</th>
                        <th class="px-5 py-3 text-right">Contrato</th>
                        <th class="px-5 py-3 text-right">Saldo</th>
                        <th class="px-5 py-3">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($client->loans as $loan)
                        <tr>
                            <td class="px-5 py-3"><a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $loan) }}">{{ $loan->folio }}</a></td>
                            <td class="px-5 py-3">{{ $loan->vehicle?->model ?? 'Sin vehiculo' }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::mxn($loan->capital) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::mxn($loan->contract_total) }}</td>
                            <td class="px-5 py-3 text-right">{{ Money::mxn(Money::decimal($loan->installments->sum(fn ($i) => Money::cents($i->remaining_amount)))) }}</td>
                            <td class="px-5 py-3">{{ StatusLabels::loan($loan->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <aside class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Resumen global</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Capital</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($summary['capital'])) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Contrato</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($summary['contract'])) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Aplicado</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($summary['applied'])) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Saldo</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($summary['balance'])) }}</dd></div>
                </dl>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-950">Calidad de cliente</h3>
                <p class="mt-3 text-2xl font-bold text-slate-950">{{ $score['score'] ?? '--' }}</p>
                <p class="mt-1 font-semibold text-[#0f766e]">{{ $score['label'] }}</p>
                <p class="mt-2 text-sm text-slate-500">{{ $score['note'] }}</p>
            </section>
        </aside>
    </div>
</x-layouts.app>
