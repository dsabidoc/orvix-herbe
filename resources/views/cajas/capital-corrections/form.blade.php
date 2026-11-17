@extends('cajas.layout')
@section('title', $kind === 'cancel' ? 'Cancelar entrega de capital' : 'Corregir entrega de capital')
@section('content')
@php($state = $delivery->effectiveState())
<a class="back" href="{{ route('cajas.credits.show', [$cut, $credit->public_id]) }}">← Volver al crédito</a>
<div class="page-head"><div><p class="eyebrow">{{ $credit->client_name }} · Solo prueba</p><h1>{{ $kind === 'cancel' ? 'Cancelar entrega de capital' : 'Corregir entrega de capital' }}</h1></div></div>
<div class="notice subtle">El comprobante original se conserva. Esta solicitud no cambia dinero hasta que Herbe la autorice. El capital autorizado es {{ \App\Support\Money::mxn(\App\Support\Money::decimal($delivery->amount_cents)) }}; su importe y las condiciones del crédito no se modifican aquí.</div>
<form class="panel pad narrow" method="post" action="{{ route('cajas.capital-corrections.preview', [$cut, $credit->public_id, $kind]) }}">@include('cajas.cuts.command-fields')
@if($kind === 'correct')
<label>Caja correcta<select name="account_id" required>@foreach($accounts as $account)<option value="{{ $account->public_id }}" @selected(old('account_id', \App\Models\CashAccount::findOrFail($state['account_id'])->public_id) === $account->public_id)>{{ $account->name }}</option>@endforeach</select></label>
<p class="small muted">Cambiar de caja corrige dónde se registró la salida; no representa otra entrega ni un traspaso físico.</p>
<label class="section">Fecha correcta de entrega<input type="date" name="delivered_on" value="{{ old('delivered_on', $state['delivered_on']) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Quién recibió el capital<input name="recipient_name" maxlength="150" value="{{ old('recipient_name', $state['recipient_name']) }}" required></label>
<label class="section">Referencia correcta<textarea name="reference" maxlength="500" required>{{ old('reference', $state['reference']) }}</textarea></label>
@else
<label>Qué pasó con el dinero<select name="cancel_type" required><option value="">Seleccionar</option><option value="not_delivered" @selected(old('cancel_type') === 'not_delivered')>Se registró por error: el dinero nunca salió</option><option value="returned" @selected(old('cancel_type') === 'returned')>Se entregó y ya regresó completo a la misma caja</option></select></label>
<label class="section">Fecha de anulación o devolución<input type="date" name="effective_on" value="{{ old('effective_on', now('America/Merida')->toDateString()) }}" min="{{ $state['delivered_on'] }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<div class="notice subtle">Si el dinero sigue con el operador, no confirmes una devolución. La cancelación requiere el capital completo en {{ \App\Models\CashAccount::findOrFail($state['account_id'])->name }}. Si el préstamo ya tiene cobros o adelantos, primero deben resolverse.</div>
<label class="check"><input type="checkbox" name="funds_confirmed" value="1" required> Confirmo que el dinero nunca salió o que ya recibí el capital completo de regreso en esa caja, según la opción indicada.</label>
@endif
<label class="section">Motivo de la solicitud<textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<label class="section">Evidencia o referencia de respaldo<textarea name="evidence" maxlength="500" required placeholder="Folio de recibo, devolución o evidencia del error">{{ old('evidence') }}</textarea></label>
<button class="btn primary" type="submit">Revisar antes y después</button>
</form>
@endsection
