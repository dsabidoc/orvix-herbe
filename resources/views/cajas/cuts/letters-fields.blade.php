@php
    $canAdvance = auth()->user()->can('cajas.advances.report');
    $selectable = fn ($a) => $a['total'] > 0 && (!$a['future'] || ($canAdvance && $a['principal'] > 0));
    $groups = $loans->filter(fn ($loan) => $loan->installments->contains(fn ($letter) => $selectable($availability[$letter->id])));
@endphp
@if($groups->isEmpty())
    <p class="muted">No hay letras disponibles para agregar a este corte.</p>
    @if($modal ?? false)<div class="dialog-actions"><button class="btn" type="button" data-dialog-close>Cerrar</button></div>@endif
@else
    <p class="muted">Marca las letras de la lista del operador. Puedes capturar un pago parcial en las letras del mes o vencidas.</p>
    <form method="post" action="{{ route('cajas.cuts.letters', $cut) }}" data-dialog-reload-conflict data-cash-letter-picker>
        @include('cajas.cuts.command-fields')
        <label class="section">Buscar letras
            <input type="search" data-cash-letter-search placeholder="Ej. RIO Día 12, folio o cliente" autocomplete="off">
        </label>
        <p class="small muted" data-cash-letter-empty role="status" hidden>No hay letras que coincidan con la búsqueda.</p>
        <div class="schedule-scroll cash-letter-scroll" role="region" aria-label="Letras disponibles" tabindex="0">
            <table class="schedule-table cash-letter-table">
                <thead>
                    <tr><th scope="col">Modelo / Día</th><th scope="col">Cliente</th><th scope="col">Núm. pagaré</th><th scope="col">Fecha vencimiento</th><th scope="col">Pago</th></tr>
                </thead>
                <tbody>
                @foreach($groups as $loan)
                @php($paymentLabel = $loan->paymentLabel())
                @foreach($loan->installments->sortBy('number') as $letter)
                    @php($a = $availability[$letter->id])
                    @continue(!$selectable($a))
                    <tr data-cash-payment-label="{{ $paymentLabel }}" data-cash-letter-row="{{ implode(' ', [$paymentLabel, $loan->folio, $loan->client_name, $letter->number, $letter->due_date->format('d/m/Y')]) }}">
                        <td>
                            <label class="check cash-letter-identity">
                                <input type="checkbox" name="installment_ids[]" value="{{ $letter->id }}" aria-label="{{ $paymentLabel }} · {{ $loan->folio }} · Pagaré {{ $letter->number }}" @checked(in_array($letter->id, old('installment_ids', [])))>
                                <span><strong>{{ $paymentLabel }}</strong><small>{{ $loan->folio }}</small></span>
                            </label>
                            <input type="hidden" name="quote_hashes[{{ $letter->id }}]" value="{{ $service->letterHash($letter, $date) }}">
                        </td>
                        <td>{{ $loan->client_name }}</td>
                        <td>{{ $letter->number }}</td>
                        <td>
                            <time datetime="{{ $letter->due_date->toDateString() }}">{{ $letter->due_date->format('d/m/Y') }}</time>
                            @if($a['future'])<span class="badge">Futura</span>
                            @elseif($letter->due_date->toDateString() < $date)<span class="badge cash-letter-overdue">Vencida</span>@endif
                        </td>
                        <td>
                        @if($a['future'])
                            <div>
                                <strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($a['principal'])) }}</strong><p class="small muted">Solo capital</p>
                                @can('cajas.advances.include-interest')
                                    <label class="check small"><input type="checkbox" name="include_interest_ids[]" value="{{ $letter->id }}" @checked(in_array($letter->id, old('include_interest_ids', [])))> Incluir intereses · {{ \App\Support\Money::mxn(\App\Support\Money::decimal($a['total'])) }}</label>
                                @endcan
                            </div>
                        @else
                            <label class="letter-amount"><span class="cash-letter-sr">Importe de pagaré {{ $letter->number }} · {{ $loan->folio }}</span>
                                <input name="amounts[{{ $letter->id }}]" inputmode="decimal" value="{{ old('amounts.'.$letter->id, \App\Support\Money::decimal($a['total'])) }}">
                                <small class="muted">Disponible {{ \App\Support\Money::mxn(\App\Support\Money::decimal($a['total'])) }}</small>
                            </label>
                        @endif
                        </td>
                    </tr>
                @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
        <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
            @if($modal ?? false)<button class="btn" type="button" data-dialog-close>Cancelar</button>@endif
            <button class="btn primary" type="submit">Agregar letras seleccionadas</button>
        </div>
    </form>
@endif
