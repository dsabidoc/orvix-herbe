<form id="save-count" data-count-balance="{{ $snapshot['expected_cents'] }}" @class(['panel pad section' => !($modal ?? false)]) method="post" action="{{ route('cajas.reconciliations.store', $account) }}">
    @csrf
    <input type="hidden" name="source_token" value="{{ $sourceToken }}">
    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    
    <label class="section">{{ $account->kind === 'bank' ? 'Disponible verificado en el banco' : 'Total de efectivo contado' }} ({{ $account->currency }})
        <input data-counted name="counted" type="number" min="0" max="999999999999.99" step="0.01" required inputmode="decimal" value="{{ old('counted') }}" placeholder="0.00">
    </label>
    <div class="notice subtle section" aria-live="polite"><strong data-count-difference>Ingresa lo contado para ver la diferencia.</strong><p class="small">Diferencia = contado − registrado. El conteo solo registra la diferencia.</p></div>
    <label class="section">Referencia del conteo o comprobante<input name="evidence" maxlength="500" required value="{{ old('evidence') }}" placeholder="Ej. Hoja de conteo de oficina, folio 12"></label>
    <label class="section">Observación <span class="muted">· Obligatoria si hay diferencia</span><textarea name="note" maxlength="1000" rows="3" placeholder="Explica el faltante o sobrante; si todavía no conoces la causa, indícalo.">{{ old('note') }}</textarea></label>
    <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Verifiqué el importe en esta caja y no incluí dinero en tránsito.</label>
    <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>@if($modal ?? false)<button type="button" class="btn" data-dialog-close>Cancelar</button>@endif<button class="btn primary" type="submit">Enviar revisión a Herbe</button>@unless($modal ?? false)<a class="btn" href="{{ route('cajas.reconciliations.create', $account) }}">Actualizar saldo y volver a contar</a>@endunless</div>

</form>
