@extends('cajas.layout')
@section('title', 'Cobros')
@section('content')
    <div class="cash-collections-page">
        <div class="page-head page-head--actions">
            <div><h1>Cobros</h1><p class="muted">Cortes por operador y fecha.</p></div>
            <div class="page-actions">
                @if(\App\Domain\Cajas\CajasSandbox::enabled() && auth()->user()->can('cajas.collections.loans.view'))
                    @if(auth()->user()->can('cajas.cuts.prepare'))<a class="btn primary" data-cash-dialog href="{{ route('cajas.cuts.create') }}"><span class="cash-action-plus" aria-hidden="true">+</span> Crear corte</a>@endif
                    @can('cajas.pending-loans.view')
                        @if(auth()->user()->can('cajas.cuts.review'))<a class="btn {{ !$reviewFilter && !auth()->user()->can('cajas.cuts.prepare') ? 'primary' : '' }}" href="{{ $reviewFilter ? route('cajas.collections') : route('cajas.collections', ['review' => 'pending']) }}">{{ $reviewFilter ? 'Todos los cortes' : 'Cortes pendientes' }}</a>@endif
                        <a class="btn" href="{{ route('cajas.credits.index') }}">Créditos pendientes</a>
                    @endcan
                @endif
            </div>
        </div>
        @if($cuts)
            <section class="panel cash-history" aria-labelledby="cash-cuts-title">
                <div class="section-head">
                    <h2 id="cash-cuts-title">{{ $reviewFilter ? 'Cortes pendientes de Herbe' : 'Mis cortes de prueba' }}</h2>
                </div>
                @if($cuts->isNotEmpty())
                    <div class="cash-history-columns" aria-hidden="true"><span>Operador y fecha</span><span>Estado</span><span>Cobros reportados</span></div>
                @endif
                @forelse($cuts as $cut)
                    <a class="cash-history-row" href="{{ route('cajas.cuts.show', $cut) }}">
                        <div class="cash-history-detail">
                            <strong>{{ $cut->operator->name }}</strong>
                            <time class="small muted" datetime="{{ $cut->cut_on->toDateString() }}">{{ $cut->cut_on->format('d/m/Y') }}</time>
                        </div>
                        @include('cajas.cuts.status', ['statusClass' => 'cash-history-status'])
                        <strong class="cash-history-amount">{{ \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cut->items_sum_amount_cents)) }}</strong>
                    </a>
                @empty
                    <div class="empty">
                        @if($reviewFilter)
                            <h2>No hay cortes pendientes de revisión</h2>
                        @elseif(auth()->user()->can('cajas.cuts.prepare'))
                            <h2>Empieza con la lista del operador</h2>
                            <p>Crea un corte y agrega las letras que van a pagar y los créditos nuevos.</p>
                        @else
                            <h2>Aún no hay cortes para consultar</h2>
                        @endif
                    </div>
                @endforelse
                @if($cuts->hasPages())<div class="row cash-history-pagination">@if($cuts->previousPageUrl())<a class="btn" href="{{ $cuts->previousPageUrl() }}">← Anteriores</a>@endif<span class="small muted">Página {{ $cuts->currentPage() }} de {{ $cuts->lastPage() }}</span>@if($cuts->nextPageUrl())<a class="btn" href="{{ $cuts->nextPageUrl() }}">Siguientes →</a>@endif</div>@endif
            </section>
        @endif
        @include('cajas.capital-corrections.inbox')
        @include('cajas.offset-corrections.inbox')
        @include('cajas.corrections.inbox')
        @can('cajas.collections.naboo.view')<section class="section panel pad"><div class="section-head"><h2>Cobros Naboo</h2><span class="badge">Sin conexión</span></div><p class="muted">Tu perfil tiene permiso para esta fuente. La integración se habilitará en una etapa posterior.</p></section>@endcan
        @include('cajas.partials.dialog')
    </div>
@endsection
