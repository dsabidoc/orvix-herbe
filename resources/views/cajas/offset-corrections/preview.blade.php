@extends('cajas.layout')
@section('title', 'Revisar solicitud de compensación')
@section('content')
<a class="back" href="{{ route('cajas.offset-corrections.create', [$cut, $credit->public_id, $kind]) }}">← Volver a preparar</a>
<div class="page-head"><div><p class="eyebrow">{{ $credit->client_name }}</p><h1>Revisar solicitud</h1></div><span class="badge soft">Sin aplicar</span></div>
@include('cajas.offset-corrections.comparison')
<section class="panel pad section"><h2>Motivo y respaldo</h2><p>{{ $data['reason'] }}</p><p class="muted">{{ $data['evidence'] }}</p></section>
<form class="panel pad narrow section" method="post" action="{{ route('cajas.offset-corrections.store', [$cut, $credit->public_id]) }}">@csrf<input type="hidden" name="quote_token" value="{{ $token }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé el antes, el después y el pendiente del corte. Solicito la autorización de Herbe.</label><button class="btn primary" type="submit">Enviar solicitud a Herbe</button></form>
@endsection
