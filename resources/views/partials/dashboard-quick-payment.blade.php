@php
    use App\Support\Money;
@endphp

<section data-quick-payment-panel>
    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
        <p class="font-bold text-slate-950">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ $loan->folio }} · {{ $loan->vehicle?->model }} {{ $loan->vehicle?->year }} · {{ $loan->operator?->name }}</p>
    </div>
    <div class="max-h-[420px] overflow-auto">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Letra</th>
                    <th class="px-4 py-3">Vence</th>
                    <th class="px-4 py-3 text-right">Saldo</th>
                    <th class="px-4 py-3 text-right">Accion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($loan->installments as $installment)
                    @php
                        $isOverdue = $installment->due_date->toDateString() < now('America/Merida')->toDateString();
                        $delinquencyCents = app(\App\Domain\Loans\DelinquencyCalculator::class)
                            ->forInstallment($installment, now('America/Merida')->toDateString());
                        $futurePrincipalOnly = app(\App\Domain\Loans\InstallmentPaymentPolicy::class)
                            ->isFutureMonth($installment, now('America/Merida'));
                        $suggestedCapitalCents = ($loan->calculation_method ?? 'regular') === 'interest_only'
                            ? Money::cents($installment->remaining_amount)
                            : app(\App\Domain\Loans\InstallmentPaymentPolicy::class)->principalRemainingCents($installment);
                    @endphp
                    <tr class="{{ $isOverdue ? 'bg-red-50/40' : '' }}">
                        <td class="px-4 py-3 font-semibold">{{ $installment->number }}</td>
                        <td class="px-4 py-3">
                            <span class="{{ $isOverdue ? 'font-semibold text-red-700' : '' }}">{{ $installment->due_date->format('d/m/Y') }}</span>
                            @if ($isOverdue)
                                <span class="ml-2 rounded bg-red-50 px-2 py-1 text-xs font-bold text-red-700">Vencida</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold {{ $isOverdue ? 'text-red-700' : '' }}">{{ Money::mxn($installment->remaining_amount) }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('collections.mark-paid', $installment) }}" data-confirm-paid data-suggested-payment-amount="{{ Money::decimal(Money::cents($installment->remaining_amount)) }}" data-collection-fee="{{ Money::decimal(Money::cents($installment->administration_fee_amount)) }}" data-installment-month="{{ $installment->due_date->format('Y-m') }}" data-suggested-capital-amount="{{ Money::decimal($suggestedCapitalCents) }}" data-suggested-delinquency="{{ Money::decimal($delinquencyCents) }}" @if ($futurePrincipalOnly) data-force-capital-advance="true" data-show-capital-advance-amount="true" @endif @if (($loan->calculation_method ?? 'regular') !== 'interest_only') data-month-interest-allowed="true" @endif>
                                @csrf
                                <input name="return_to" type="hidden" value="dashboard">
                                <input name="operated_on" type="hidden" value="{{ now('America/Merida')->toDateString() }}">
                                <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                <input name="operator_surcharge_amount" type="hidden" value="0">
                                <input name="external_concepts_amount" type="hidden" value="0">
                                <input name="additional_charge_amount" type="hidden" value="0">
                                <input name="delinquency_amount" type="hidden" value="0">
                                <button class="rounded-md bg-[#0d9488] px-3 py-2 text-xs font-bold text-white" type="submit">Pagado</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
