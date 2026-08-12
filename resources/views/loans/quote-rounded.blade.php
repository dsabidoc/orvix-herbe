@php use App\Support\Money; @endphp

<x-layouts.app title="Vista previa de prestamo">
    <div class="space-y-6">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Vista previa de prestamo</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ ($data['calculation_method'] ?? 'regular') === 'rounded' ? 'Comparar opciones con redondeo' : 'Confirmar prestamo regular' }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ ($data['calculation_method'] ?? 'regular') === 'rounded' ? 'Ambas opciones cobran el mismo total. Solo cambia el primer pago y el importe uniforme de los pagos restantes.' : 'Revisa el calendario y asigna inversionistas antes de crear el prestamo.' }}</p>
                </div>
                <form method="POST" action="{{ route('loans.create.restore') }}">
                    @csrf
                    @foreach ($data as $field => $value)
                        @if (! is_array($value))
                            <input name="{{ $field }}" type="hidden" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" type="submit">Regresar a editar</button>
                </form>
            </div>
            <dl class="mt-5 grid gap-3 md:grid-cols-4">
                <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Capital</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($quote['input']['capital_cents'])) }}</dd></div>
                <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Interes mensual</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($quote['input']['interest_monthly_cents'])) }}</dd></div>
                <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Cobranza total</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($quote['input']['collection_total_cents'])) }}</dd></div>
                <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Total general</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($quote['input']['total_cents'])) }}</dd></div>
            </dl>
        </section>

        <div class="grid gap-6 {{ count($quote['options']) > 1 ? 'xl:grid-cols-2' : '' }}">
            @foreach ($quote['options'] as $key => $option)
                <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 p-5">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#0f766e]">{{ $option['name'] }}</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $option['description'] }}</h3>
                        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Primer pago</dt><dd class="font-bold">{{ Money::mxn($option['first_payment']) }}</dd></div>
                            <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Pagos restantes</dt><dd class="font-bold">{{ $option['remaining_payments'] }} de {{ Money::mxn($option['regular_payment']) }}</dd></div>
                            <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Interes total</dt><dd class="font-bold">{{ Money::mxn(Money::decimal($quote['input']['interest_total_cents'])) }}</dd></div>
                            <div class="rounded-md bg-slate-50 p-3"><dt class="text-sm text-slate-500">Total general</dt><dd class="font-bold">{{ Money::mxn($option['total']) }}</dd></div>
                        </dl>
                        <form class="mt-4" method="POST" action="{{ route('loans.confirm-rounded') }}">
                            @csrf
                            @foreach ($data as $field => $value)
                                @if (! is_array($value))
                                    <input name="{{ $field }}" type="hidden" value="{{ $value }}">
                                @endif
                            @endforeach
                            <input name="selected_option" type="hidden" value="{{ $key }}">
                            <div class="mb-4 rounded-md bg-slate-50 p-3">
                                <h4 class="font-bold text-slate-950">Inversionistas</h4>
                                <p class="mt-1 text-sm text-slate-500">Opcional al crear. Si los capturas ahora, deben cubrir {{ Money::mxn(Money::decimal($quote['input']['capital_cents'])) }} de capital y 100% de intereses; tambien puedes asignarlos despues desde el detalle del prestamo.</p>
                                <div class="mt-3 space-y-2">
                                    @for ($index = 0; $index < 4; $index++)
                                        <div class="grid gap-2 sm:grid-cols-[1fr_130px_120px]">
                                            <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" name="investors[{{ $index }}][investor_id]">
                                                <option value="">Seleccionar inversionista</option>
                                                @foreach ($investors as $investor)
                                                    <option value="{{ $investor->id }}">{{ $investor->name }} · {{ Money::mxn($investor->available_capital) }}</option>
                                                @endforeach
                                            </select>
                                            <input class="rounded-md border border-slate-300 bg-white px-3 py-2 text-right text-sm" name="investors[{{ $index }}][capital_amount]" type="number" step="0.01" min="0" placeholder="Capital">
                                            <input class="rounded-md border border-slate-300 bg-white px-3 py-2 text-right text-sm" name="investors[{{ $index }}][interest_share_percent]" type="number" step="0.0001" min="0" max="100" placeholder="% interes">
                                        </div>
                                    @endfor
                                </div>
                            </div>
                            <button class="w-full rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Seleccionar {{ strtolower($option['name']) }} y crear prestamo</button>
                        </form>
                    </div>

                    <div class="max-h-[520px] overflow-auto">
                        <table class="w-full min-w-[900px] text-left text-sm">
                            <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Pago</th>
                                    <th class="px-4 py-3">Vence</th>
                                    <th class="px-4 py-3 text-right">Capital</th>
                                    <th class="px-4 py-3 text-right">Interes</th>
                                    <th class="px-4 py-3 text-right">Cobranza</th>
                                    <th class="px-4 py-3 text-right">Pagaré</th>
                                    <th class="px-4 py-3 text-right">Saldo anterior</th>
                                    <th class="px-4 py-3 text-right">Saldo posterior</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($option['installments'] as $installment)
                                    <tr>
                                        <td class="px-4 py-3">{{ $installment['number'] }}</td>
                                        <td class="px-4 py-3">{{ \Carbon\CarbonImmutable::parse($installment['due_date'])->format('d/m/Y') }}</td>
                                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment['principal']) }}</td>
                                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment['interest']) }}</td>
                                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment['administration_fee']) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold">{{ Money::mxn($installment['amount']) }}</td>
                                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment['previous_balance']) }}</td>
                                        <td class="px-4 py-3 text-right">{{ Money::mxn($installment['balance']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-layouts.app>
