@extends('cajas.layout')
@section('title', $kind === 'cancel' ? 'Revertir traspaso aceptado' : 'Corregir traspaso aceptado')
@section('content')
@php($state = $transfer->effectiveTransferState())
<a class="back" href="{{ route('cajas.operations.show', $transfer) }}">← Volver al traspaso</a>
<div class="page-head"><div><p class="eyebrow">{{ $transfer->source->name }} → {{ $transfer->destination->name }}</p><h1>{{ $kind === 'cancel' ? 'Revertir traspaso aceptado' : 'Corregir traspaso aceptado' }}</h1></div></div>
<div class="notice subtle">{{ $kind === 'cancel' ? 'Al autorizar, el importe completo vuelve de la caja destino a la de origen. El comprobante original queda en el historial.' : 'Puedes corregir las fechas y la referencia. Los cambios se aplican cuando Herbe autoriza.' }}</div>
<form class="panel pad narrow" method="post" action="{{ route('cajas.transfer-corrections.preview', [$transfer, $kind]) }}">@include('cajas.transfer-corrections.command-fields')
@if($kind === 'correct')
<label>Fecha correcta de entrega<input type="date" name="delivered_on" value="{{ old('delivered_on', $state['delivered_on']) }}" min="{{ $transfer->operated_on->toDateString() }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Fecha correcta de recepción<input type="date" name="received_on" value="{{ old('received_on', $state['received_on']) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Referencia correcta<textarea name="reference" maxlength="500" required>{{ old('reference', $state['reference']) }}</textarea></label>
@else
<label>Qué ocurrió<select name="cancel_type" required><option value="">Seleccionar</option><option value="not_transferred" @selected(old('cancel_type') === 'not_transferred')>El traspaso se registró por error; el dinero nunca salió del origen</option><option value="returned_origin" @selected(old('cancel_type') === 'returned_origin')>Ya recibí de regreso el importe completo en el origen</option></select></label>
<label class="section">Fecha de anulación o devolución<input type="date" name="effective_on" value="{{ old('effective_on', now('America/Merida')->toDateString()) }}" min="{{ max($state['received_on'], $transfer->received_on->toDateString()) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<div class="notice subtle">Esta opción requiere todo el dinero en {{ $transfer->source->name }}. Si sigue en el destino, primero debe devolverse y quien recibe en origen debe confirmarlo. No registres además un traspaso de regreso ni un ingreso manual por la misma devolución.</div>
<label class="check"><input type="checkbox" name="funds_confirmed" value="1" required> Confirmo que el dinero nunca salió o que ya recibí y acepté la devolución completa en el origen, según la opción indicada. Esta devolución no se registró como otro movimiento.</label>
@endif
<label class="section">Motivo<textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<label class="section">Evidencia o referencia de respaldo<textarea name="evidence" maxlength="500" required>{{ old('evidence') }}</textarea></label>
<button class="btn primary" type="submit">Revisar antes y después</button>
</form>
@endsection
