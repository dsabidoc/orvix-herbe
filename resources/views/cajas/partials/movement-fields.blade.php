<form id="save-movement" @class(['panel pad narrow' => !($modal ?? false)]) method="post" action="{{ route('cajas.movements.store', [$account, $kind]) }}">
        @csrf<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $token) }}"><input type="hidden" name="origin" value="manual">
        <div class="two-cols"><label>Importe (MXN)<input name="amount" inputmode="decimal" placeholder="0.00" value="{{ old('amount') }}" pattern="[0-9]+(\.[0-9]{1,2})?" required></label><label>{{ $kind === 'transfer' ? 'Fecha de preparación' : 'Fecha del movimiento' }}<input type="date" name="operated_on" value="{{ old('operated_on', now('America/Merida')->toDateString()) }}" max="{{ now('America/Merida')->toDateString() }}" required></label></div>
        @if($kind === 'transfer')
            @if($destinations->count() === 1)
                <input type="hidden" name="destination_id" value="{{ $destinations->first()->public_id }}">
                <p class="section">Caja destino <strong>{{ $destinations->first()->name }}</strong></p>
            @elseif($destinations->isNotEmpty())
            <label class="section">Caja destino<select name="destination_id" required><option value="">Selecciona un destino autorizado</option>@foreach($destinations as $destination)<option value="{{ $destination->public_id }}" @selected(old('destination_id') === $destination->public_id)>{{ $destination->name }}</option>@endforeach</select></label>
            @endif
            @if($destinations->isEmpty())<div class="notice subtle">No tienes destinos habilitados desde esta caja. Solicita su configuración.</div>@endif
            <p class="footnote">Confirma la entrega cuando pases el efectivo. El destino recibe el saldo al aceptarlo.</p>
        @else
            <p class="footnote">Movimiento manual de prueba.</p>
        @endif
        <label class="section">{{ $kind === 'expense' ? '¿A quién y por qué se entrega?' : 'Concepto y referencia' }}<textarea name="reason" rows="3" maxlength="500" required placeholder="Describe el movimiento para poder identificarlo después">{{ old('reason') }}</textarea></label>
        @if($kind !== 'transfer')<label class="check section"><input type="checkbox" name="confirmed" value="1" required> Confirmo {{ $kind === 'income' ? 'el ingreso' : 'el egreso' }} de este importe ficticio.</label>@endif
        <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>@if($modal ?? false)<button class="btn" type="button" data-dialog-close>Cancelar</button>@endif<button class="btn primary" type="submit" @disabled($kind === 'transfer' && $destinations->isEmpty())>{{ $kind === 'transfer' ? 'Preparar traspaso' : ($kind === 'income' ? 'Registrar ingreso' : 'Registrar egreso') }}</button>@unless($modal ?? false)<a class="btn" href="{{ route('cajas.accounts.show', $account) }}">Volver</a>@endunless</div>
    </form>
