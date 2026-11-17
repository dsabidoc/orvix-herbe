@extends('cajas.layout')
@section('title', 'Recibir entrega del corte')
@section('content')
@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents)))
<a class="back" href="{{ route('cajas.cuts.show', $cut) }}">← Volver al corte</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">{{ $cut->operator->name }} · Corte {{ $cut->cut_on->format('d/m/Y') }}</p><h1>Recibir la entrega</h1><p class="muted">Registra el importe contado y dónde quedó. Todo es de prueba.</p></div><div class="page-actions"><button class="btn primary" type="submit" form="save-receipt">Registrar entrega de prueba</button></div></div>
<div class="cut-summary">
    <section class="panel pad"><p class="eyebrow">Cobros reportados</p><div class="amount">{{ $money($custody['reported']) }}</div></section>
    <section class="panel pad"><p class="eyebrow">Dinero ya recibido</p><div class="amount">{{ $money($custody['received']) }}</div></section>
    <section class="panel pad"><p class="eyebrow">Falta conciliar</p><div class="amount">{{ $money($custody['remaining']) }}</div><p class="small muted">Se completa con dinero contado o compensaciones confirmadas.</p></section>
</div>
@include('cajas.cuts.receipt-form-fields')
@endsection
