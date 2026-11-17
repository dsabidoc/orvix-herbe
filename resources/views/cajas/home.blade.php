@extends('cajas.layout')
@section('title', 'Inicio')
@section('content')
    <div class="page-head page-head--actions">
        <div><h1>Inicio</h1><p class="muted">Tus cobros, tu saldo y lo que sigue por hacer.</p></div>
        <div class="page-actions">
            @if(\App\Domain\Cajas\CajasSandbox::enabled() && auth()->user()->can('cajas.cuts.prepare') && auth()->user()->can('cajas.collections.loans.view'))
                <a class="btn primary" data-cash-dialog href="{{ route('cajas.cuts.create') }}">Crear corte</a>
            @elseif(\App\Domain\Cajas\CajasSandbox::enabled() && auth()->user()->can('cajas.cuts.review') && auth()->user()->can('cajas.collections.loans.view') && auth()->user()->can('cajas.pending-loans.view'))
                <a class="btn primary" href="{{ route('cajas.collections', ['review' => 'pending']) }}">Revisar cortes</a>
            @endif
            @if($pendingTransfers->isNotEmpty())<a class="btn" href="#incoming-transfers">Revisar entregas</a>@endif
            @if($accounts->count() === 1)<a class="btn" href="{{ route('cajas.accounts.show', $accounts->first()) }}">Mi caja</a>@endif
        </div>
    </div>
    @if($pendingCuts->isNotEmpty())<div class="section-head"><h2>Cortes pendientes de revisión</h2><a href="{{ route('cajas.collections', ['review' => 'pending']) }}">Ver pendientes →</a></div><section class="panel">@foreach($pendingCuts as $cut)<a class="movement-row" href="{{ route('cajas.cuts.show', $cut) }}"><div class="grow"><strong>{{ $cut->operator->name }}</strong><p class="small muted">{{ $cut->cut_on->format('d/m/Y') }} · Entrega conciliada</p></div><span>Revisar →</span></a>@endforeach</section>@endif
    @if($pendingTransfers->isNotEmpty())
        <section id="incoming-transfers">
            <div class="section-head"><h2>Entregas por revisar</h2><span class="badge soft">{{ $pendingTransfers->count() }}</span></div>
            @include('cajas.partials.transfers', ['transfers' => $pendingTransfers])
        </section>
    @endif
    @can('cajas.accounts.view')
        <div class="section-head"><h2>{{ $accounts->count() === 1 ? 'Mi caja' : 'Mis cajas' }}</h2></div>
        @include('cajas.partials.accounts')
    @endcan
@include('cajas.differences.inbox')
    @include('cajas.manual-corrections.inbox')
    @include('cajas.reconciliations.inbox')
    @include('cajas.transfer-corrections.inbox')
@include('cajas.capital-corrections.inbox')
@include('cajas.offset-corrections.inbox')
    @include('cajas.corrections.inbox')
    @include('cajas.partials.dialog')
@endsection
