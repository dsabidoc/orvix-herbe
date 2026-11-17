@php
    use App\Domain\Loans\DelinquencyCalculator;
    use App\Support\Money;

    $cutDateString = $cut->period_starts_on->toDateString();
    $cutMonthEndString = $cut->period_starts_on->copy()->endOfMonth()->toDateString();
@endphp

<section class="mt-4 rounded-lg border border-slate-200" data-quick-payment-panel>
    <div class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ $loan->folio }}</p>
            <h4 class="mt-1 text-lg font-bold text-slate-950">{{ $loan->vehicle?->model ?? 'Vehiculo' }} · Dia {{ $loan->payment_day }}</h4>
            <p class="text-sm text-slate-500">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
        </div>
        <form class="w-full rounded-lg border border-red-200 bg-red-50 p-3 lg:w-auto lg:min-w-[330px]" method="POST" action="{{ route('loans.settle', $loan) }}">
            @csrf
            <input name="return_to" type="hidden" value="cut">
            <input name="cut_id" type="hidden" value="{{ $cut->id }}">
            <input name="advance_loan_id" type="hidden" value="{{ $loan->id }}">
            <input name="settled_on" type="hidden" value="{{ $cutDateString }}">
            <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div>
                    <label class="text-xs font-semibold text-red-700" for="settlement_reason_{{ $loan->id }}">Liquidar en este corte</label>
                    <select class="mt-1 w-full rounded-md border border-red-200 bg-white px-3 py-2 text-sm" id="settlement_reason_{{ $loan->id }}" name="settlement_reason" required>
                        <option value="pronto_pago_cliente">Pronto pago del cliente</option>
                        <option value="dejo_de_pagar">Dejo de pagar; cobrador liquida</option>
                    </select>
                    <p class="mt-1 text-xs text-red-700">Monto a liquidar: <strong>{{ Money::mxn(Money::decimal($quote['total_cents'] ?? 0)) }}</strong></p>
                    @if ($pendingPaymentCount > 0)
                        <p class="mt-1 text-xs font-semibold text-amber-700">Incluye los pagos reportados en este corte; se aplicaran al confirmarlo.</p>
                    @endif
                </div>
                <button class="min-h-11 rounded-md bg-red-700 px-5 py-2.5 text-sm font-bold text-white" type="submit">Liquidar</button>
            </div>
        </form>
    </div>
    <div class="max-h-[min(48vh,34rem)] overflow-auto overscroll-contain">
        <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Letra</th>
                    <th class="px-4 py-3">Vence</th>
                    <th class="px-4 py-3 text-right">Abono capital</th>
                    <th class="px-4 py-3 text-right">Interes</th>
                    <th class="px-4 py-3 text-right">Subtotal</th>
                    <th class="px-4 py-3 text-right">Accion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($loan->installments as $installment)
                    @php
                        $isAfterCutDate = $installment->due_date->toDateString() > $cutDateString;
                        $isCapitalAdvance = $installment->due_date->toDateString() > $cutMonthEndString;
                        $suggestedCapitalCents = ($loan->calculation_method ?? 'regular') === 'interest_only'
                            ? Money::cents($installment->remaining_amount)
                            : app(\App\Domain\Loans\InstallmentPaymentPolicy::class)->principalRemainingCents($installment);
                        $canAdvanceCapital = $isCapitalAdvance && $suggestedCapitalCents > 0;
                        $delinquencyCents = $isAfterCutDate
                            ? 0
                            : app(DelinquencyCalculator::class)->forInstallment($installment, $cutDateString);
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $installment->number }}/{{ $loan->term_months }}</td>
                        <td class="px-4 py-3">{{ $installment->due_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment->principal_amount) }}</td>
                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment->interest_amount) }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ Money::mxn(Money::decimal(Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount))) }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('collections.mark-paid', $installment) }}"
                                data-confirm-paid
                                data-suggested-payment-amount="{{ Money::decimal(Money::cents($installment->remaining_amount)) }}"
                                data-collection-fee="{{ Money::decimal(Money::cents($installment->administration_fee_amount)) }}"
                                data-installment-month="{{ $installment->due_date->format('Y-m') }}"
                                data-fixed-payment-month="{{ $cut->period_starts_on->format('Y-m') }}"
                                data-suggested-capital-amount="{{ Money::decimal($suggestedCapitalCents) }}"
                                data-suggested-delinquency="{{ Money::decimal($delinquencyCents) }}"
                                @if ($isCapitalAdvance) data-force-capital-advance="true" data-show-capital-advance-amount="true" @endif
                                @if (($loan->calculation_method ?? 'regular') !== 'interest_only') data-month-interest-allowed="true" @endif
                                @if ($canAdvanceCapital) data-capital-advance-allowed="true" @endif>
                                @csrf
                                <input name="return_to" type="hidden" value="cut">
                                <input name="cut_id" type="hidden" value="{{ $cut->id }}">
                                <input name="advance_loan_id" type="hidden" value="{{ $loan->id }}">
                                <input name="operated_on" type="hidden" value="{{ $cutDateString }}">
                                <input name="contract_amount" type="hidden" value="{{ $installment->remaining_amount }}">
                                <input name="operator_surcharge_amount" type="hidden" value="0">
                                <input name="external_concepts_amount" type="hidden" value="0">
                                <input name="additional_charge_amount" type="hidden" value="0">
                                <input name="delinquency_amount" type="hidden" value="0">
                                <input name="notes" type="hidden" value="{{ $isCapitalAdvance ? 'Adelanto registrado desde corte' : 'Cobro registrado desde corte' }}">
                                <button class="rounded-md px-3 py-1.5 text-xs font-bold {{ $isCapitalAdvance && ! $canAdvanceCapital ? 'cursor-not-allowed bg-slate-200 text-slate-500' : 'bg-[#0d9488] text-white' }}" type="submit" @disabled($isCapitalAdvance && ! $canAdvanceCapital)>
                                    {{ $isCapitalAdvance ? 'Abonar capital' : 'Pagado' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
