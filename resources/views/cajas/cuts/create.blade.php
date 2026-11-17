@extends('cajas.layout')
@section('title', 'Crear corte')
@section('content')
<a class="back" href="{{ route('cajas.collections') }}">← Cobros</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">Preparación de prueba</p><h1>Crear corte</h1><p class="muted">Empieza con el operador y la fecha de su lista.</p></div><div class="page-actions"><button class="btn primary" type="submit" form="open-cut" @disabled($operators->isEmpty())>Abrir corte</button></div></div>
@include('cajas.cuts.create-fields')
@endsection
