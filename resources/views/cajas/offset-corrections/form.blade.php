@extends('cajas.layout')
@section('title', $kind === 'cancel' ? 'Cancelar compensación' : 'Corregir compensación')
@section('content')
@php($state = $offset->effectiveState())
<a class="back" href="{{ route('cajas.credits.show', [$cut, $credit->public_id]) }}">← Volver al crédito</a>
<div class="page-head"><div><p class="eyebrow">{{ $credit->client_name }} · Solo prueba</p><h1>{{ $kind === 'cancel' ? 'Cancelar compensación' : 'Corregir compensación' }}</h1></div></div>
<div class="notice subtle">El original se conserva y Herbe autoriza la solicitud. El capital completo y el crédito vinculado no se cambian aquí. Ninguna resolución registra entradas o salidas de caja.</div>
<form class="panel pad narrow" method="post" action="{{ route('cajas.offset-corrections.preview', [$cut, $credit->public_id, $kind]) }}">@include('cajas.cuts.command-fields')
@if($kind === 'correct')
<label>Fecha correcta de compensación<input type="date" name="executed_on" value="{{ old('executed_on', $state['executed_on']) }}" min="{{ $cut->cut_on->toDateString() }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Referencia correcta<textarea name="reference" maxlength="500" required>{{ old('reference', $state['reference']) }}</textarea></label>
@else
<label>Qué pasó con el capital<select name="cancel_type" required><option value="">Seleccionar</option><option value="not_delivered" @selected(old('cancel_type') === 'not_delivered')>Se registró por error: el operador no entregó el capital</option><option value="returned_operator" @selected(old('cancel_type') === 'returned_operator')>El capital regresó completo al operador</option></select></label>
<label class="section">Fecha de cancelación<input type="date" name="effective_on" value="{{ old('effective_on', now('America/Merida')->toDateString()) }}" min="{{ $state['executed_on'] }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<div class="notice subtle">El importe vuelve a quedar pendiente del operador. Cuando entregue el dinero a Adriana, debe registrarse como recepción del corte. Si el capital sigue prestado, regresó parcialmente o hay cobros reportados, no se puede cancelar por este flujo.</div>
<label class="check"><input type="checkbox" name="funds_confirmed" value="1" required> Confirmo que el operador conserva o recuperó todo el capital y debe entregarlo a la caja. Esta solicitud no confirma una recepción.</label>
@endif
<label class="section">Motivo de la solicitud<textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<label class="section">Evidencia o referencia de respaldo<textarea name="evidence" maxlength="500" required placeholder="Lista, folio o constancia del operador">{{ old('evidence') }}</textarea></label>
<button class="btn primary" type="submit">Revisar antes y después</button>
</form>
@endsection
