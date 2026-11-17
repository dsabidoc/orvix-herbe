@extends('cajas.layout')
@section('title', 'Crédito pendiente')
@section('content')
<a class="back" href="{{ route('cajas.cuts.show', $cut) }}">← Volver al corte</a><div class="page-head page-head--actions"><div><p class="eyebrow">{{ $cut->operator->name }} · {{ $cut->cut_on->format('d/m/Y') }}</p><h1>Nuevo crédito pendiente</h1><p class="muted">Captura las condiciones de prueba y revisa el calendario antes de guardarlas.</p></div><div class="page-actions"><button class="btn primary" type="submit" form="quote-credit">Revisar calendario</button></div></div>
@include('cajas.cuts.credit-form-fields')
@endsection
