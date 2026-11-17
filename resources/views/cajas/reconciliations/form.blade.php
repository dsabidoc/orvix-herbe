@extends('cajas.layout')
@section('title', 'Revisión de saldo')
@section('content')
<a class="back" href="{{ route('cajas.accounts.show', $account) }}">← {{ $account->name }}</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">Revisión de saldo · Prueba</p><h1>Revisar saldo</h1><p class="muted">{{ $account->name }} · {{ $account->kind === 'bank' ? 'Verifica el saldo disponible del banco.' : 'Cuenta el efectivo que tienes en esta caja.' }}</p></div><div class="page-actions"><button class="btn primary" type="submit" form="save-count">Enviar revisión a Herbe</button></div></div>
@include('cajas.reconciliations.summary')
@include('cajas.partials.reconciliation-fields')
@endsection
