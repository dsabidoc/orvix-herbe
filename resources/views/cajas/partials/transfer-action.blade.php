<form id="transfer-{{ $action }}" @class(['panel pad section narrow' => !($modal ?? false), 'section' => $modal ?? false]) method="post" action="{{ route('cajas.operations.transition', [$operation, $action]) }}">
    @csrf
    @isset($heading)<h2>{{ $heading }}</h2>@endisset
    @if(in_array($action, ['deliver', 'accept']))<label class="section">{{ $action === 'deliver' ? 'Fecha de entrega' : 'Fecha de recepción' }}<input type="date" name="effective_on" value="{{ old('effective_on', now('America/Merida')->toDateString()) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>@endif
    @if($action !== 'deliver')<label class="section">{{ $action === 'accept' ? ($operation->status === 'disputed' ? 'Cómo se resolvió la diferencia' : 'Observación (opcional)') : 'Motivo' }}<textarea name="reason" rows="2" maxlength="500" @required($action !== 'accept' || $operation->status === 'disputed')>{{ old('reason') }}</textarea></label>@endif
    <label class="check section"><input type="checkbox" name="confirmed" value="1" required> {{ ['deliver' => 'Confirmo que entregué el importe completo.', 'accept' => 'Revisé y recibí el importe completo.', 'dispute' => 'Confirmo la diferencia; el dinero seguirá en tránsito.', 'cancel' => 'Confirmo que todavía no entregué dinero.'][$action] }}</label>
    <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
        @if(($modal ?? false) && in_array($action, ['deliver', 'accept']))<button type="button" class="btn" data-dialog-close>Cancelar</button>@endif
        <button class="btn {{ $action === 'dispute' ? '' : 'primary' }}" type="submit">{{ $button }}</button>
    </div>
</form>
