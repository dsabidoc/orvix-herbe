<p class="muted">Elige un préstamo y cuántas letras completas cubrir. El adelanto empieza por la última letra disponible y se aplica solo a capital.</p>
@php($hasAvailable = false)
@foreach($loans as $loan)
    @php($letters = $loan->installments->sortByDesc('number')->filter(fn ($letter) => $availability[$letter->id]['principal'] > 0)->take(120))
    @continue($letters->isEmpty())
    <details class="loan-group" @if(!$hasAvailable) open @endif>
        <summary><strong>{{ $loan->paymentLabel() }}</strong><span class="small muted">{{ $loan->folio }} · {{ $loan->client_name }}</span></summary>
        <form method="post" action="{{ route('cajas.cuts.advance', $cut) }}" class="pad" data-dialog-reload-conflict>
            @include('cajas.cuts.command-fields')
            <input type="hidden" name="loan_id" value="{{ $loan->id }}">
            <input type="hidden" name="loan_hash" value="{{ $service->loanHash($loan, $date) }}">
            <label>Cuántas letras cubrir
                <select name="letter_count" required>
                    @php($sum = 0)
                    @foreach($letters as $letter)
                        @php($sum += $availability[$letter->id]['principal'])
                        <option value="{{ $loop->iteration }}">{{ $loop->iteration }} {{ $loop->first ? 'letra' : 'letras' }} · {{ \App\Support\Money::mxn(\App\Support\Money::decimal($sum)) }}</option>
                    @endforeach
                </select>
            </label>
            <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
                @if($modal ?? false)<button class="btn" type="button" data-dialog-close>Cancelar</button>@endif
                <button class="btn primary" type="submit">Guardar adelanto</button>
            </div>
        </form>
    </details>
    @php($hasAvailable = true)
@endforeach
@if(!$hasAvailable)
    <p class="muted">No hay préstamos con capital disponible para adelantar.</p>
    @if($modal ?? false)<div class="dialog-actions"><button class="btn" type="button" data-dialog-close>Cerrar</button></div>@endif
@endif
