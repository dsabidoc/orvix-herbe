@extends('cajas.layout')
@section('title', 'Cajas')
@section('content')
    <div class="page-head page-head--actions">
        <div><h1>Mis cajas</h1></div>
        <div class="page-actions">
            @can('cajas.accounts.manage')<a class="btn" href="{{ route('cajas.settings') }}#new-account">Crear caja</a>@endcan
        </div>
    </div>
    @include('cajas.partials.accounts')
@include('cajas.differences.inbox')
    @include('cajas.manual-corrections.inbox')
    @include('cajas.reconciliations.inbox')
    @include('cajas.transfer-corrections.inbox')
@endsection
