@extends('cajas.layout')
@section('title', 'Revisar crédito pendiente')
@section('content')
<a class="back" href="{{ route('cajas.cuts.credit-form', $cut) }}">← Volver a captura</a><div class="page-head page-head--actions"><div><p class="eyebrow">Vista previa · Datos de prueba</p><h1>{{ $quote['terms']['client_name'] }}</h1><p class="muted">Revisa las condiciones antes de guardar el crédito pendiente.</p></div><div class="page-actions"><button class="btn primary" type="submit" form="save-credit">Guardar crédito pendiente</button></div></div>
@include('cajas.cuts.credit-quote-fields')
@endsection
