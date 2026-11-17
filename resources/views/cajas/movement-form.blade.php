@extends('cajas.layout')
@php($label = ['income' => 'Registrar ingreso', 'expense' => 'Registrar egreso', 'transfer' => 'Preparar traspaso'][$kind])
@section('title', $label)
@section('content')
    <a class="back" href="{{ route('cajas.accounts.show', $account) }}">← {{ $account->name }}</a>
    <div class="page-head page-head--actions"><div><p class="eyebrow">Movimiento de prueba</p><h1>{{ $label }}</h1><p class="muted">{{ $account->name }} · Disponible {{ \App\Support\Money::mxn(\App\Support\Money::decimal($account->balanceCents())) }}</p></div><div class="page-actions"><button class="btn primary" type="submit" form="save-movement" @disabled($kind === 'transfer' && $destinations->isEmpty())>{{ $kind === 'transfer' ? 'Preparar traspaso' : 'Registrar movimiento de prueba' }}</button></div></div>
    @include('cajas.partials.movement-fields')
@endsection
