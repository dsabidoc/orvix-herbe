@extends('cajas.layout')
@section('title', 'Solicitud de corrección')
@section('content')
<a class="back" href="{{ route('cajas.cuts.show', $cut) }}">← Volver al corte</a>
<div class="page-head"><div><p class="eyebrow">{{ $cut->operator->name }} · Corte {{ $cut->cut_on->format('d/m/Y') }}</p><h1>Solicitud de corrección</h1><p class="muted">{{ $correction->creator?->name }} · {{ $correction->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p></div><span class="badge soft">{{ $correction->statusLabel() }}</span></div>
@include('cajas.corrections.comparison')
<section class="panel pad section"><h2>Motivo y referencia</h2><p>{{ $correction->reason }}</p><p class="muted">{{ $correction->reference }}</p><p class="small muted break">Solicitud {{ $correction->public_id }} · Versión original {{ $correction->base_version }}</p></section>
@if($correction->status === 'pending')
<div class="notice subtle section">Los conceptos y el dinero siguen como estaban. El corte está bloqueado para nuevas entregas, envío y autorización mientras se resuelve esta solicitud.</div>
@can('cajas.corrections.resolve')
<form class="panel pad narrow section" method="post" action="{{ route('cajas.corrections.resolve', [$cut, $correction, 'approve']) }}">@include('cajas.cuts.command-fields')<h2>Autorizar corrección</h2><p class="muted">Se volverán a comprobar la versión, las reservas y el saldo disponible de la caja afectada.</p><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé el antes, el después y la evidencia. Autorizo aplicar esta corrección de prueba.</label><button class="btn primary" type="submit">Autorizar y aplicar corrección</button></form>
<details class="panel pad narrow section"><summary>Rechazar la propuesta</summary><form method="post" action="{{ route('cajas.corrections.resolve', [$cut, $correction, 'reject']) }}">@include('cajas.cuts.command-fields')<label>Motivo del rechazo<textarea name="note" rows="3" maxlength="1000" required></textarea></label><label class="check"><input type="checkbox" name="confirmed" value="1" required> Rechazar sin cambiar importes ni dinero.</label><button class="btn" type="submit">Rechazar corrección</button></form></details>
@endcan
@else
<section class="notice section"><strong>{{ $correction->status === 'approved' ? 'Se aplicó la corrección autorizada en esta prueba.' : 'Se rechazó la propuesta sin aplicar cambios.' }}</strong><p>{{ $correction->resolver?->name }} · {{ $correction->resolved_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p>@if($correction->resolution_note)<p>{{ $correction->resolution_note }}</p>@endif<p>La comparación de arriba conserva lo que se revisó en esta solicitud. Consulta el corte para ver el estado vigente.</p></section>
@endif
@endsection
