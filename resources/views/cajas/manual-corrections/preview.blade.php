@extends('cajas.layout')
@section('title', 'Revisar solicitud de movimiento manual')
@section('content')
<a class="back" href="{{ route('cajas.manual-corrections.create', [$operation, $kind]) }}">← Volver a preparar</a>
<div class="page-head"><div><p class="eyebrow">{{ $operation->source->name }}</p><h1>Revisar solicitud</h1></div><span class="badge soft">Sin aplicar</span></div>
@include('cajas.manual-corrections.comparison')
<section class="panel pad section"><h2>Motivo y respaldo</h2><p>{{ $data['reason'] }}</p><p class="muted">{{ $data['evidence'] }}</p></section>
<form class="panel pad narrow section" method="post" action="{{ route('cajas.manual-corrections.store', [$operation]) }}">@csrf<input type="hidden" name="quote_token" value="{{ $token }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé el antes, el después y el efecto en la caja. Solicito la autorización de Herbe.</label><button class="btn primary" type="submit">Enviar solicitud a Herbe</button></form>
@endsection
