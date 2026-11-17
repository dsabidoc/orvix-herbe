@extends('cajas.layout')
@section('title', 'Revisión de movimiento manual')
@section('content')
<a class="back" href="{{ route('cajas.operations.show', $operation) }}">← Movimiento e historial</a>
<div class="page-head"><div><p class="eyebrow">{{ $operation->source->name }} · {{ $operation->kindLabel() }}</p><h1>{{ $kind === 'cancel' ? 'Revertir movimiento manual' : 'Corregir movimiento manual' }}</h1><p class="muted">Solicitó {{ $record->creator->name }} · {{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p></div><span class="badge soft">{{ $record->statusLabel() }}</span></div>
@include('cajas.manual-corrections.comparison')
<section class="panel pad section"><h2>Motivo y respaldo</h2><p>{{ $record->reason }}</p><p class="muted">{{ $record->evidence }}</p></section>
@if($record->status === 'pending')
<div class="notice subtle section">El saldo cambia cuando Herbe autoriza la solicitud.</div>
@if(auth()->id() !== $record->created_by && auth()->user()->can('cajas.manual.correct-resolve') && $operation->source->allows(auth()->user(), 'adjust') && $operation->source->active)
<form class="panel pad section" method="post" action="{{ route('cajas.manual-corrections.resolve', [$operation, $record, 'approve']) }}">@include('cajas.transfer-corrections.command-fields')
<h2>Revisión de Herbe</h2><label class="section">Nota de revisión<textarea name="note" required maxlength="1000">{{ old('note') }}</textarea></label>
<label class="check section"><input type="checkbox" name="confirmed" value="1" required> Revisé el antes, el después y la evidencia. Confirmé que el ajuste o devolución no se registró por otro flujo.</label>
<div class="row section"><button class="btn primary" type="submit">Autorizar {{ $kind === 'cancel' ? 'reversión' : 'corrección' }}</button><button class="btn" type="submit" formaction="{{ route('cajas.manual-corrections.resolve', [$operation, $record, 'reject']) }}">Rechazar solicitud</button></div></form>
@endif
@else
<section class="notice section"><strong>{{ $record->statusLabel() }}</strong><p>{{ $record->resolver->name }} · {{ $record->resolved_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>{{ $record->resolution_note }}</p>@if($record->adjustment_operation_id)<a class="btn" href="{{ route('cajas.operations.show', $record->adjustment) }}">Ver ajuste vinculado</a>@endif</section>
@endif
@endsection
