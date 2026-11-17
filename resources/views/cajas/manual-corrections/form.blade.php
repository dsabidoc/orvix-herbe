@extends('cajas.layout')
@section('title', $kind === 'cancel' ? 'Revertir movimiento manual' : 'Corregir movimiento manual')
@section('content')
@php($state = $operation->effectiveManualState())
<a class="back" href="{{ route('cajas.operations.show', $operation) }}">← Volver al movimiento</a>
<div class="page-head"><div><p class="eyebrow">{{ $operation->source->name }} · {{ $operation->kindLabel() }}</p><h1>{{ $kind === 'cancel' ? 'Revertir movimiento manual' : 'Corregir movimiento manual' }}</h1></div></div>
<div class="notice subtle">Herbe revisará la solicitud antes de aplicar cambios al saldo.</div>
<form class="panel pad narrow" method="post" action="{{ route('cajas.manual-corrections.preview', [$operation, $kind]) }}">@include('cajas.transfer-corrections.command-fields')
@if($kind === 'correct')
<label>Importe correcto<input name="amount" type="number" min="0.01" max="999999999999.99" step="0.01" inputmode="decimal" required value="{{ old('amount', \App\Support\Money::decimal($state['amount_cents'])) }}"></label>
<label class="section">Fecha correcta del movimiento<input type="date" name="operated_on" value="{{ old('operated_on', $state['operated_on']) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Concepto o referencia correctos<textarea name="reference" maxlength="500" required>{{ old('reference', $state['reference']) }}</textarea></label>
<div class="notice subtle section">Indica lo que realmente se recibió o pagó. Si solo cambia la fecha o el concepto, no habrá movimiento de dinero. Si cambia el importe, se ajustará únicamente la diferencia.</div>
@else
<label>Qué ocurrió<select name="cancel_type" required><option value="">Seleccionar</option><option value="not_occurred" @selected(old('cancel_type') === 'not_occurred')>Registro por error o duplicado; este movimiento no ocurrió</option><option value="returned" @selected(old('cancel_type') === 'returned')>{{ $operation->kind === 'income' ? 'Ya devolvimos todo el ingreso desde esta caja' : 'Ya recibimos de regreso todo el egreso en esta caja' }}</option></select></label>
<div class="notice subtle section">{{ $operation->kind === 'income' ? 'La reversión descontará el ingreso vigente de esta caja. Debe haber saldo suficiente.' : 'La reversión devolverá el egreso vigente al saldo de esta caja.' }} Si hubo una entrega, confirma primero la devolución completa.</div>
@endif
<label class="section">Fecha del ajuste o anulación<input type="date" name="effective_on" value="{{ old('effective_on', now('America/Merida')->toDateString()) }}" min="{{ app(\App\Domain\Cajas\CashManualCorrectionService::class)->minimumDate($operation) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="check section"><input type="checkbox" name="funds_confirmed" value="1" required> Verifiqué el importe y el respaldo. La entrega o devolución indicada ya ocurrió, o confirmé que el registro fue erróneo. Este ajuste o devolución no está registrado en otro movimiento.</label>
<label class="section">Motivo<textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<label class="section">Comprobante o evidencia<textarea name="evidence" maxlength="500" required>{{ old('evidence') }}</textarea></label>
<button class="btn primary section" type="submit">Revisar antes y después</button>
</form>
@endsection
