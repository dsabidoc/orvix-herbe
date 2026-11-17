<section @class(['panel pad' => !($modal ?? false), 'credit-preview' => $modal ?? false])><div class="two-cols"><div><p class="eyebrow">Capital</p><div class="amount money">{{ \App\Support\Money::mxn($quote['terms']['capital']) }}</div><p class="muted">{{ $quote['terms']['term_months'] }} meses · {{ $quote['terms']['monthly_rate_percent'] }}% mensual</p></div><div><p class="eyebrow">Entrega propuesta</p><h2>{{ $quote['terms']['planned_funding'] === 'offset' ? 'Compensación en el corte' : 'Desde una caja' }}</h2><p class="muted">Sin ejecutar dinero. Pendiente de alta en Préstamos.</p></div></div>
@include('cajas.cuts.schedule', ['terms' => $quote['terms'], 'schedule' => $quote['schedule']])
</section>
<form id="save-credit" @class(['panel pad section narrow' => !($modal ?? false), 'section' => $modal ?? false]) method="post" action="{{ route('cajas.cuts.credit-store', $cut) }}">
    @csrf<input type="hidden" name="quote_token" value="{{ $token }}">
    @can('cajas.pending-loans.authorize')<label class="check"><input type="checkbox" name="authorize" value="1" checked> Autorizo estas condiciones de prueba. El crédito seguirá pendiente de Herbe.</label>@else<p class="muted">Se guardará con condiciones por autorizar.</p>@endcan
    <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Revisé monto, calendario y forma de entrega propuesta.</label>
    <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
        @if($modal ?? false)<button class="btn" type="button" data-dialog-edit>Volver a captura</button>@endif
        <button class="btn primary" type="submit">Guardar crédito pendiente</button>
    </div>
</form>
