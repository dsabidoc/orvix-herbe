@extends('cajas.layout')
@section('title', 'Resolución de entrega de capital')
@section('content')
<a class="back" href="{{ route('cajas.credits.show', [$cut, $credit->public_id]) }}">← Crédito e historial</a>
<div class="page-head"><div><p class="eyebrow">{{ $credit->client_name }}</p><h1>{{ $kind === 'cancel' ? 'Cancelar entrega de capital' : 'Corregir entrega de capital' }}</h1><p class="muted">Solicitó {{ $record->creator?->name }} · {{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p></div><span class="badge soft">{{ $record->statusLabel() }}</span></div>
@include('cajas.capital-corrections.comparison')
<section class="panel pad section"><h2>Motivo y respaldo</h2><p>{{ $record->reason }}</p><p class="muted">{{ $record->evidence }}</p></section>
@if($record->status === 'pending')
<div class="notice subtle section">La entrega original sigue vigente. El corte y las nuevas conversiones están bloqueados mientras Herbe resuelve esta solicitud.</div>
@can('cajas.disbursements.correct-resolve')
<form class="panel pad narrow section" method="post" action="{{ route('cajas.capital-corrections.resolve', [$cut, $credit->public_id, $record, 'approve']) }}">@include('cajas.cuts.command-fields')<label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé la evidencia, el efecto en caja y el estado del préstamo. Autorizo esta resolución de prueba.</label><button class="btn primary" type="submit">Autorizar {{ $kind === 'cancel' ? 'cancelación' : 'corrección' }}</button></form>
<details class="panel pad narrow section"><summary>Rechazar sin aplicar cambios</summary><form method="post" action="{{ route('cajas.capital-corrections.resolve', [$cut, $credit->public_id, $record, 'reject']) }}">@include('cajas.cuts.command-fields')<label>Motivo del rechazo<textarea name="note" required maxlength="1000"></textarea></label><label class="check section"><input type="checkbox" name="confirmed" value="1" required> Rechazar y conservar la entrega como estaba.</label><button class="btn" type="submit">Rechazar solicitud</button></form></details>
@endcan
@else
<div class="notice section"><strong>{{ $record->statusLabel() }}</strong><p>{{ $record->resolver?->name }} · {{ $record->resolved_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p>@if($record->resolution_note)<p>{{ $record->resolution_note }}</p>@endif<p>La comparación conserva lo autorizado en esta solicitud. Consulta el crédito para ver su estado actual.</p></div>
@endif
@endsection
