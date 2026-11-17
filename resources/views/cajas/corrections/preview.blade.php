@extends('cajas.layout')
@section('title', 'Revisar corrección')
@section('content')
<a class="back" href="{{ route('cajas.corrections.create', [$cut, $proposal['kind'], $proposal['target_id']]) }}">← Volver a propuesta</a>
<div class="page-head"><div><p class="eyebrow">Vista previa · Prueba</p><h1>Antes y después</h1><p class="muted">Todavía no cambió ningún importe ni reserva.</p></div></div>
@include('cajas.corrections.comparison')
<section class="panel pad section"><h2>Motivo</h2><p>{{ $reason }}</p><p class="muted">Referencia: {{ $reference }}</p></section>
<form class="panel pad narrow section" method="post" action="{{ route('cajas.corrections.store', $cut) }}">@csrf<input type="hidden" name="quote_token" value="{{ $token }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé la propuesta. El corte quedará bloqueado hasta que Herbe la resuelva.</label><button class="btn primary" type="submit">Solicitar corrección a Herbe</button></form>
@endsection
