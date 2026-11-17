@extends('cajas.layout')
@section('title', 'Revisar aclaración')
@section('content')
<a class="back" href="{{ route('cajas.differences.create', [$account, $count, 'kind' => $kind]) }}">← Volver a preparar</a><div class="page-head page-head--actions"><div><p class="eyebrow">{{ $account->name }} · Revisión de saldo #{{ $count->id }}</p><h1>Revisar aclaración</h1></div><div class="page-actions"><button class="btn primary" type="submit" form="send-difference">Enviar aclaración a Herbe</button></div></div>
@include('cajas.differences.comparison')
<section class="panel pad section"><h2>Causa y respaldo</h2><p>{{ $data['reason'] }}</p><p class="muted">{{ $data['evidence'] }}</p></section>
<form id="send-difference" class="panel pad section" method="post" action="{{ route('cajas.differences.store', [$account, $count]) }}">@csrf<input type="hidden" name="quote_token" value="{{ $token }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé los vínculos y solicito autorización de Herbe para resolver esta diferencia.</label><button class="btn primary section" type="submit">Enviar aclaración a Herbe</button></form>
@endsection
