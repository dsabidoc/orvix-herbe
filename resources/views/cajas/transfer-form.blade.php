@extends('cajas.layout')
@section('title', $title)
@section('content')
    <a class="back" href="{{ route('cajas.operations.show', $operation) }}">← Volver al comprobante</a>
    <div class="page-head"><h1>{{ $title }}</h1></div>
    <section class="panel pad narrow">@include('cajas.partials.transfer-fields')</section>
@endsection
