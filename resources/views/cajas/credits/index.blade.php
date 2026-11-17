@extends('cajas.layout')
@section('title', 'Créditos pendientes')
@section('content')
<a class="back" href="{{ route('cajas.collections') }}">← Cobros</a>
<div class="page-head"><div><p class="eyebrow">De la lista del operador al préstamo</p><h1>Créditos pendientes</h1><p class="muted">Herbe revisa las condiciones y reutiliza la entrega de capital ya confirmada.</p></div><span class="badge soft">Solo prueba</span></div>
<div class="row section"><a class="btn {{ !$converted && !$cancelled ? 'primary' : '' }}" href="{{ route('cajas.credits.index') }}">Pendientes</a><a class="btn {{ $converted ? 'primary' : '' }}" href="{{ route('cajas.credits.index', ['status' => 'converted']) }}">Vigentes de prueba</a><a class="btn {{ $cancelled ? 'primary' : '' }}" href="{{ route('cajas.credits.index', ['status' => 'cancelled']) }}">Cancelados</a></div>
<section class="panel section">
@forelse($credits as $credit)
<a class="movement-row" href="{{ route('cajas.credits.show', [$credit->cut, $credit->public_id]) }}"><div class="grow"><strong>{{ $credit->client_name }}</strong><p class="small muted">{{ $credit->cut->operator->name }} · Corte {{ $credit->cut->cut_on->format('d/m/Y') }}</p><p class="small muted">{{ $converted ? $credit->conversion->loan->folio : app(\App\Domain\Cajas\CashLoanConversionService::class)->readiness($credit->cut, $credit)['reason'] }}</p></div><strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($credit->capital_cents)) }}</strong><span aria-hidden="true">→</span></a>
@empty<div class="empty"><h2>{{ $converted ? 'Todavía no hay préstamos convertidos' : 'No hay créditos pendientes' }}</h2><p>Los créditos se preparan dentro del corte del operador.</p></div>@endforelse
@if($credits->hasPages())<div class="row pad">@if($credits->previousPageUrl())<a class="btn" href="{{ $credits->previousPageUrl() }}">← Anteriores</a>@endif<span>Página {{ $credits->currentPage() }}</span>@if($credits->nextPageUrl())<a class="btn" href="{{ $credits->nextPageUrl() }}">Siguientes →</a>@endif</div>@endif
</section>
@endsection
