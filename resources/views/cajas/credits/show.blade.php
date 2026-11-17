@extends('cajas.layout')
@section('title', $credit->fundingCancelled() ? 'Entrega cancelada' : ($conversion ? 'Préstamo vigente de prueba' : 'Revisar crédito pendiente'))
@section('content')
@php($displayCredit = $conversion ? $conversion->snapshot['credit'] : $credit->toArray())
@php($effective = $disbursement?->effectiveState() ?? $offset?->effectiveState())
@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents)))
<a class="back" href="{{ route('cajas.credits.index') }}">← Créditos pendientes</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">{{ $cut->operator->name }} · Corte {{ $cut->cut_on->format('d/m/Y') }}</p><h1>{{ $displayCredit['client_name'] }}</h1><p class="muted">{{ $conversion ? $conversion->loan->folio : 'Revisa las condiciones que autorizó Adriana.' }}</p></div><div class="page-actions">
    @if(!$conversion && $readiness['ready'] && auth()->user()->can('cajas.pending-loans.convert-test'))
        <a class="btn primary" href="#convert-credit">Revisar y convertir</a>
    @elseif(!$conversion && !$offset && !$disbursement && $credit->planned_funding === 'cash' && $deliveryState['ready'] && auth()->user()->can('cajas.pending-loans.disburse-test') && auth()->user()->can('cajas.expenses.record') && auth()->user()->can('cajas.accounts.view'))
        <a class="btn primary" href="{{ route('cajas.disbursements.create', [$cut, $credit->public_id]) }}">Entregar capital desde caja</a>
    @endif
    <span class="badge soft">{{ ($effective['cancelled'] ?? false) ? 'Cancelado · Prueba' : ($conversion ? 'Vigente · Prueba' : 'Crédito pendiente') }}</span></div></div>
<div class="cut-summary"><div class="panel pad"><p class="eyebrow">Capital del crédito</p><div class="money amount">{{ $money($displayCredit['capital_cents']) }}</div></div><div class="panel pad"><p class="eyebrow">Plazo</p><div class="money amount">{{ $displayCredit['terms']['term_months'] }} meses</div></div><div class="panel pad"><p class="eyebrow">Dinero nuevo al convertir</p><div class="money amount">{{ $money(0) }}</div></div></div>
@if($disbursement)
<section class="notice section"><h2>{{ $effective['cancelled'] ? 'Entrega de capital cancelada' : 'Capital entregado desde caja' }}</h2><p>{{ $money($disbursement->amount_cents) }} · {{ \App\Models\CashAccount::findOrFail($effective['account_id'])->name }} · {{ $effective['delivered_on'] }}</p><p>Recibió: {{ $effective['recipient_name'] }}. Registró: {{ $disbursement->creator?->name }}.</p><p>{{ $effective['reference'] }}</p><p class="small break">Comprobante {{ $disbursement->public_id }}</p>
@if(auth()->user()->can('cajas.accounts.view') && \App\Models\CashAccount::visibleTo(auth()->user())->whereKey($disbursement->cash_account_id)->exists())<a class="btn" href="{{ route('cajas.operations.show', $disbursement->operation) }}">Ver salida original</a>@endif</section>
@include('cajas.capital-corrections.history')
@elseif(!$conversion && $credit->planned_funding === 'cash')
@can('cajas.pending-loans.disburse-test')
<div class="panel pad section"><h2>Entrega de capital</h2><p class="muted">{{ $deliveryState['reason'] }}</p></div>
@endcan
@endif
@if($offset) @include('cajas.offset-corrections.history') @endif
<section class="panel pad section"><h2>Condiciones y calendario</h2><p class="muted">Tasa mensual {{ $displayCredit['terms']['monthly_rate_percent'] }}% · Inicio {{ $displayCredit['terms']['start_date'] }} · Primer pago {{ $displayCredit['terms']['first_payment_date'] }}</p>@if($displayCredit['notes'])<p>{{ $displayCredit['notes'] }}</p>@endif @include('cajas.cuts.schedule', ['schedule' => $displayCredit['schedule'], 'terms' => $displayCredit['terms']])</section>
@if($conversion)
@if($effective['cancelled'] ?? false)<div class="notice subtle section">El préstamo de prueba quedó inactivo. Su conversión y calendario se conservan como historial; no se aplicó una segunda cancelación ni se borraron pagos.</div>@endif
<section class="notice section"><h2>{{ ($effective['cancelled'] ?? false) ? 'Conversión histórica' : 'Conversión completada' }}</h2><p>{{ $conversion->creator?->name }} · {{ $conversion->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>@if($conversion->cash_pending_disbursement_id)Se reutilizó la entrega desde caja #{{ $conversion->cash_pending_disbursement_id }} por {{ $money($conversion->snapshot['disbursement']['amount_cents']) }}. No se volvió a descontar dinero de la caja.@else Se reutilizó la compensación #{{ $conversion->cash_cut_offset_id }} por {{ $money($conversion->snapshot['offset']['amount_cents']) }}. No se creó otro desembolso ni se volvió a descontar del corte.@endif</p>@if($conversion->loan->loan_id)<p>Alta registrada en la copia de Préstamos. Folio {{ $conversion->loan->folio }}.</p>@endif<p class="small break">Referencia {{ $conversion->public_id }} · Versión del corte {{ $conversion->cut_version }}</p></section>
@unless($conversion->loan->collectible)<div class="notice subtle section">El calendario completo se conservó. Esta modalidad tiene solo intereses, comisiones o IVA: sus cobros todavía no están habilitados en el catálogo de Cajas. El préstamo de prueba sí quedó creado.</div>@endunless
@else
<section class="notice subtle section"><strong>{{ $readiness['reason'] }}</strong><p>La conversión conserva el crédito pendiente, su calendario y la referencia de entrega. No crea préstamos ni movimientos en el sistema real.</p></section>
@if($readiness['ready'])
@can('cajas.pending-loans.convert-test')
<form id="convert-credit" class="panel pad narrow section" method="post" action="{{ route('cajas.credits.store', [$cut, $credit->public_id]) }}">@include('cajas.cuts.command-fields')<input type="hidden" name="loan_hash" value="{{ $sourceHash }}"><h2>Convertir a vigente</h2><label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé cliente, capital, calendario y entrega de capital. Autorizo crear un único préstamo de prueba.</label><button class="btn primary" type="submit">Convertir a vigente de prueba</button></form>
@else<div class="notice section">Herbe o una persona con permiso de conversión debe autorizar este paso.</div>@endcan
@endif
@endif
<a class="btn section" href="{{ route('cajas.cuts.show', $cut) }}">Ver corte e historial</a>
@endsection
