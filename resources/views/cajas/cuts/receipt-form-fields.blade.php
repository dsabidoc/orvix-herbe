@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents)))
<form id="save-receipt" @class(['panel pad narrow section' => !($modal ?? false)]) method="post" action="{{ route('cajas.cuts.receive', $cut) }}">
    @include('cajas.cuts.command-fields')
    <div class="two-cols section"><label>Importe recibido (MXN)<input name="amount" inputmode="decimal" placeholder="0.00" value="{{ old('amount', ($modal ?? false) ? '' : '0.00') }}" required><small class="muted">Puede ser parcial. Usa cero solo si confirmarás una compensación.</small></label><label>Fecha de la entrega<input name="received_on" type="date" value="{{ old('received_on', now('America/Merida')->toDateString()) }}" min="{{ $cut->cut_on->toDateString() }}" max="{{ now('America/Merida')->toDateString() }}" required></label></div>
    @if($accounts->count() === 1)
        <input type="hidden" name="account_id" value="{{ $accounts->first()->public_id }}">
        <p class="section">Caja donde quedó el dinero <strong>{{ $accounts->first()->name }}</strong></p>
    @elseif($accounts->isNotEmpty())
    <label class="section">Caja donde quedó el dinero<select name="account_id"><option value="">Selecciona una caja si recibiste dinero</option>@foreach($accounts as $account)<option value="{{ $account->public_id }}" @selected(old('account_id') === $account->public_id)>{{ $account->name }}</option>@endforeach</select></label>
    @endif
    @if($accounts->isEmpty())<p class="muted">No tienes una caja de prueba habilitada para ingresar cobros de Préstamos.</p>@endif
    @if($offsets->isNotEmpty())
        <details class="section"><summary>Parte de los cobros se usó para créditos nuevos</summary><p class="muted">Marca únicamente los créditos cuyo capital se compensó completo en esta entrega de prueba. Autorizar condiciones antes no ejecutó esta compensación.</p>
        @foreach($offsets as $credit)<label class="check check-row"><input type="checkbox" name="offset_ids[]" value="{{ $credit->id }}" @checked(in_array($credit->id, old('offset_ids', [])))><span>Confirmo compensación: {{ $credit->client_name }}<small>{{ $money($credit->capital_cents) }} · Capital completo, sin entrada ni salida de caja</small></span></label>@endforeach
        </details>
    @endif
    <label class="section">Referencia de la entrega<textarea name="reference" rows="2" required maxlength="500" placeholder="Lista del operador, entrega en oficina o referencia del depósito">{{ old('reference') }}</textarea></label>
    <div class="notice subtle section">La primera entrega bloquea la edición del corte. Puedes solicitar correcciones después.</div>
    <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Confirmo el dinero contado, su caja y las compensaciones marcadas en esta prueba.</label>
    <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
        @if($modal ?? false)<button type="button" class="btn" data-dialog-close>Cancelar</button>@endif
        <button class="btn primary" type="submit">Registrar entrega de prueba</button>
    </div>
</form>
