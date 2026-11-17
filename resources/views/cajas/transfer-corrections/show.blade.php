@extends('cajas.layout')
@section('title', 'Resolución de traspaso')
@section('content')
<a class="back" href="{{ route('cajas.operations.show', $transfer) }}">← Traspaso e historial</a>
<div class="page-head"><div><p class="eyebrow">{{ $transfer->source->name.' → '.$transfer->destination->name }}</p><h1>{{ $kind === 'cancel' ? 'Revertir traspaso' : 'Corregir traspaso' }}</h1><p class="muted">Solicitó {{ $record->creator?->name }} · {{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p></div><span class="badge soft">{{ $record->statusLabel() }}</span></div>
@include('cajas.transfer-corrections.comparison')
<section class="panel pad section"><h2>Motivo y respaldo</h2><p>{{ $record->reason }}</p><p class="muted">{{ $record->evidence }}</p></section>
@if($record->status === 'pending')
<div class="notice subtle section">El traspaso original sigue vigente. No se admite otra solicitud sobre este traspaso mientras Herbe resuelve. Las cajas pueden seguir operando.</div>
@can('cajas.transfers.correct-resolve')
<form class="panel pad narrow section" method="post" action="{{ route('cajas.transfer-corrections.resolve', [$transfer, $record, 'approve']) }}">@include('cajas.transfer-corrections.command-fields')<label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé el efecto en ambas cajas y la evidencia de devolución o error. Autorizo esta resolución de prueba.</label><button class="btn primary" type="submit">Autorizar {{ $kind === 'cancel' ? 'reversión' : 'corrección' }}</button></form>
<details class="panel pad narrow section"><summary>Rechazar sin aplicar cambios</summary><form method="post" action="{{ route('cajas.transfer-corrections.resolve', [$transfer, $record, 'reject']) }}">@include('cajas.transfer-corrections.command-fields')<label>Motivo del rechazo<textarea name="note" required maxlength="1000"></textarea></label><label class="check section"><input type="checkbox" name="confirmed" value="1" required> Rechazar y conservar el traspaso como estaba.</label><button class="btn" type="submit">Rechazar solicitud</button></form></details>
@endcan
@else
<div class="notice section"><strong>{{ $record->statusLabel() }}</strong><p>{{ $record->resolver?->name }} · {{ $record->resolved_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p>@if($record->resolution_note)<p>{{ $record->resolution_note }}</p>@endif<p>La comparación conserva lo autorizado en esta solicitud. Consulta el traspaso para ver su estado actual.</p>@if($record->reversal_operation_id)<a class="btn" href="{{ route('cajas.operations.show', $record->reversal) }}">Ver movimiento vinculado</a>@endif</div>
@endif
@endsection
