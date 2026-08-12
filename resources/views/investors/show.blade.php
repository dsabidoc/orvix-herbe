@php
    use App\Support\Money;

    $activeInvestments = $investor->investments
        ->filter(fn ($investment) => $investment->status === 'active' && $investment->loan?->status === 'active')
        ->values();
    $investedCents = $activeInvestments->sum(fn ($investment) => Money::cents($investment->amount));
    $pendingReturnCapitalCents = $activeInvestments->sum(function ($investment) {
        $loanCapitalCents = Money::cents($investment->loan?->capital);

        if ($loanCapitalCents <= 0) {
            return 0;
        }

        $capitalShareRate = Money::cents($investment->amount) / $loanCapitalCents;

        return $investment->loan?->installments?->sum(function ($installment) use ($capitalShareRate) {
            $remainingCents = Money::cents($installment->remaining_amount);
            $principalCents = Money::cents($installment->principal_amount);
            $operationalCents = $principalCents + Money::cents($installment->interest_amount);

            if ($remainingCents <= 0 || $principalCents <= 0 || $operationalCents <= 0) {
                return 0;
            }

            $pendingPrincipalCents = (int) round($principalCents * min(1, $remainingCents / $operationalCents));

            return (int) round($pendingPrincipalCents * $capitalShareRate);
        }) ?? 0;
    });
    $capitalTotalCents = Money::cents($investor->available_capital) + $pendingReturnCapitalCents;
    $estimatedInterestCents = $investor->investments->sum(function ($investment) {
        return $investment->loan?->installments?->sum(fn ($installment) => (int) round(\App\Support\Money::cents($installment->interest_amount) * (float) $investment->investor_share_rate)) ?? 0;
    });
@endphp

<x-layouts.app title="Inversionista · {{ $investor->name }}">
    <div class="space-y-6">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Perfil de inversionista</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ $investor->name }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ $investor->email ?: 'Sin correo' }} · {{ $investor->phone ?: 'Sin celular' }}</p>
                </div>
                <span class="self-start rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ $investor->status === 'active' ? 'Activo' : 'Inactivo' }}</span>
            </div>
            <dl class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-md bg-blue-50 p-3 ring-1 ring-blue-100"><dt class="text-sm text-blue-700">Capital total</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn(Money::decimal($capitalTotalCents)) }}</dd></div>
                <div class="rounded-md bg-indigo-50 p-3 ring-1 ring-indigo-100"><dt class="text-sm text-indigo-700">Capital disponible</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn($investor->available_capital) }}</dd></div>
                <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Capital invertido</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn(Money::decimal($investedCents)) }}</dd></div>
                <div class="rounded-md bg-emerald-50 p-3 ring-1 ring-emerald-100"><dt class="text-sm text-emerald-700">Capital retornado</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn($investor->returned_capital_balance) }}</dd></div>
                <div class="rounded-md bg-amber-50 p-3 ring-1 ring-amber-100"><dt class="text-sm text-amber-700">Interes generado</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ Money::mxn($investor->generated_interest_balance) }}</dd></div>
            </dl>
        </section>

        <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
            <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 class="font-bold text-slate-950">Prestamos asignados</h3>
                    <p class="mt-1 text-sm text-slate-500">Consulta de cartera ligada al inversionista. No se muestra operador.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[840px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Prestamo</th>
                                <th class="px-5 py-3">Vehiculo</th>
                                <th class="px-5 py-3 text-right">Capital aportado</th>
                                <th class="px-5 py-3 text-right">% Interes</th>
                                <th class="px-5 py-3 text-right">Interes estimado</th>
                                <th class="px-5 py-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($investor->investments as $investment)
                                @php
                                    $loan = $investment->loan;
                                    $interestCents = $loan?->installments?->sum(fn ($installment) => (int) round(Money::cents($installment->interest_amount) * (float) $investment->investor_share_rate)) ?? 0;
                                @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        @if ($loan)
                                            <a class="font-bold text-[#0f766e]" href="{{ route('loans.show', $loan) }}">{{ $loan->folio }}</a>
                                        @else
                                            <p class="font-bold text-[#0f766e]">Sin prestamo</p>
                                        @endif
                                        <p class="mt-1 text-xs text-slate-500">{{ $loan?->client?->first_name }} {{ $loan?->client?->last_name }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">{{ $loan?->vehicle?->brand }} {{ $loan?->vehicle?->model }} {{ $loan?->vehicle?->year }}</td>
                                    <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($investment->amount) }}</td>
                                    <td class="px-5 py-3 text-right">{{ number_format((float) $investment->investor_share_rate * 100, 2) }}%</td>
                                    <td class="px-5 py-3 text-right">{{ Money::mxn(Money::decimal($interestCents)) }}</td>
                                    <td class="px-5 py-3"><span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">{{ $loan?->status === 'active' ? 'Activo' : 'Cerrado' }}</span></td>
                                </tr>
                            @empty
                                <tr><td class="px-5 py-8 text-slate-500" colspan="6">Sin prestamos asignados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="space-y-6">
                @if ($canManage)
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Registrar retornos</h3>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('investors.returns.credit', $investor) }}">
                            @csrf
                            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="returned_capital" type="number" step="0.01" min="0" placeholder="Capital retornado">
                            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="generated_interest" type="number" step="0.01" min="0" placeholder="Interes generado">
                            <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="2" placeholder="Nota interna"></textarea>
                            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar retornos</button>
                        </form>
                    </section>
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Reinvertir a capital</h3>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('investors.reinvest', $investor) }}">
                            @csrf
                            <label class="flex items-center justify-between gap-3 rounded-md bg-slate-50 p-3 text-sm">
                                <span>Capital retornado</span>
                                <input name="include_returned_capital" type="checkbox" value="1">
                            </label>
                            <label class="flex items-center justify-between gap-3 rounded-md bg-slate-50 p-3 text-sm">
                                <span>Interes generado</span>
                                <input name="include_generated_interest" type="checkbox" value="1">
                            </label>
                            <button class="w-full rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white">Convertir a capital</button>
                        </form>
                    </section>
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Retiro directo de capital</h3>
                        <p class="mt-1 text-sm text-slate-500">Disponible: {{ Money::mxn($investor->available_capital) }}</p>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('investors.withdrawals.direct', $investor) }}">
                            @csrf
                            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="amount" type="number" step="0.01" min="1" max="{{ $investor->available_capital }}" placeholder="Monto a retirar">
                            <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="2" placeholder="Nota interna"></textarea>
                            <button class="w-full rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white">Registrar retiro</button>
                        </form>
                    </section>
                @else
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="font-bold text-slate-950">Solicitar retiro</h3>
                        <p class="mt-1 text-sm text-slate-500">Disponible: {{ Money::mxn($investor->available_capital) }}</p>
                        <form class="mt-4 space-y-3" method="POST" action="{{ route('investors.withdrawals.request', $investor) }}">
                            @csrf
                            <input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="amount" type="number" step="0.01" min="1" max="{{ $investor->available_capital }}" placeholder="Monto a retirar">
                            <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="2" placeholder="Comentario opcional"></textarea>
                            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Enviar solicitud</button>
                        </form>
                    </section>
                @endif

                <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4"><h3 class="font-bold text-slate-950">Solicitudes de retiro</h3></div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($investor->withdrawalRequests as $withdrawal)
                            <div class="p-5 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-slate-950">{{ Money::mxn($withdrawal->amount) }}</p>
                                        <p class="mt-1 text-slate-500">{{ $withdrawal->created_at->format('d/m/Y H:i') }}</p>
                                    </div>
                                    <span class="rounded px-2 py-1 text-xs font-bold {{ $withdrawal->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : ($withdrawal->status === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700') }}">{{ $withdrawal->status === 'approved' ? 'Aprobada' : ($withdrawal->status === 'rejected' ? 'Rechazada' : 'En revision') }}</span>
                                </div>
                                @if ($canManage && $withdrawal->status === 'submitted')
                                    <form class="mt-3 grid gap-2" method="POST" action="{{ route('investors.withdrawals.process', $withdrawal) }}">
                                        @csrf
                                        <textarea class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="admin_notes" rows="2" placeholder="Nota administrativa"></textarea>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button class="rounded-md bg-[#0d9488] px-3 py-2 text-sm font-bold text-white" name="action" value="approve">Aprobar</button>
                                            <button class="rounded-md bg-red-700 px-3 py-2 text-sm font-bold text-white" name="action" value="reject">Rechazar</button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="p-5 text-sm text-slate-500">Sin solicitudes.</p>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4"><h3 class="font-bold text-slate-950">Movimientos de capital</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[780px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Fecha</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3 text-right">Monto</th><th class="px-5 py-3 text-right">Saldo antes</th><th class="px-5 py-3 text-right">Saldo despues</th><th class="px-5 py-3">Nota</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($investor->capitalMovements as $movement)
                            <tr><td class="px-5 py-3">{{ $movement->created_at->format('d/m/Y H:i') }}</td><td class="px-5 py-3">{{ str_replace('_', ' ', $movement->type) }}</td><td class="px-5 py-3 text-right font-semibold">{{ Money::mxn($movement->amount) }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($movement->balance_before) }}</td><td class="px-5 py-3 text-right">{{ Money::mxn($movement->balance_after) }}</td><td class="px-5 py-3 text-slate-500">{{ $movement->notes }}</td></tr>
                        @empty
                            <tr><td class="px-5 py-8 text-slate-500" colspan="6">Sin movimientos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
