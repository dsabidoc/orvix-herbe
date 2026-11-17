@extends('cajas.layout')
@section('title', 'Entregar capital')
@section('content')
@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents)))
<a class="back" href="{{ route('cajas.credits.show', [$cut, $credit->public_id]) }}">← Volver al crédito</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">{{ $cut->operator->name }} · Corte {{ $cut->cut_on->format('d/m/Y') }}</p><h1>Entregar capital</h1><p class="muted">{{ $credit->client_name }}</p></div>@if($accounts->isNotEmpty())<div class="page-actions"><button class="btn primary" type="submit" form="save-disbursement">Confirmar entrega de capital</button></div>@endif</div>
<div class="cut-summary"><section class="panel pad"><p class="eyebrow">Capital autorizado</p><div class="money amount">{{ $money($credit->capital_cents) }}</div><p class="small muted">Entrega completa, sin cambiar condiciones.</p></section><section class="panel pad"><p class="eyebrow">Salida de la caja</p><div class="money amount">−{{ $money($credit->capital_cents) }}</div><p class="small muted">Se descuenta al confirmar la entrega.</p></section><section class="panel pad"><p class="eyebrow">Al convertir a vigente</p><div class="money amount">{{ $money(0) }}</div><p class="small muted">Herbe reutiliza este desembolso.</p></section></div>
<div class="notice subtle section">Este dinero sale de una caja; no reduce lo que el operador debe entregar por los cobros del corte. Confirma únicamente cuando hayas entregado el capital en esta prueba. La preparación del corte quedará bloqueada y conservará su historial.</div>
@if($accounts->isEmpty())
<div class="panel pad section"><h2>No hay una caja habilitada para entregar capital</h2><p class="muted">Necesitas una caja de prueba activa, en MXN, con Préstamos habilitado y permiso para egresar.</p></div>
@else
<form id="save-disbursement" class="panel pad narrow section" method="post" action="{{ route('cajas.disbursements.store', [$cut, $credit->public_id]) }}">@include('cajas.cuts.command-fields')<input type="hidden" name="loan_hash" value="{{ $sourceHash }}">
@if($accounts->count() === 1)
    <input type="hidden" name="account_id" value="{{ $accounts->first()->public_id }}">
    <p>Caja de origen <strong>{{ $accounts->first()->name }}</strong> · Disponible {{ $money($accounts->first()->balanceCents()) }}</p>
@else
<label>Caja de origen<select name="account_id" required><option value="">Seleccionar caja</option>@foreach($accounts as $account)<option value="{{ $account->public_id }}" @selected(old('account_id') === $account->public_id)>{{ $account->name }} · Disponible {{ $money($account->balanceCents()) }}</option>@endforeach</select></label>
@endif
<label class="section">Fecha de entrega<input type="date" name="delivered_on" value="{{ old('delivered_on', now('America/Merida')->toDateString()) }}" min="{{ max($cut->cut_on->toDateString(), $credit->terms['start_date']) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
<label class="section">Quién recibió el capital<input name="recipient_name" maxlength="150" value="{{ old('recipient_name', $cut->operator->name) }}" required><span class="small muted">Operador o persona que recibió físicamente el efectivo o la transferencia.</span></label>
<label class="section">Referencia de entrega<textarea name="reference" rows="3" maxlength="500" required placeholder="Recibo firmado, folio de transferencia o referencia de la entrega">{{ old('reference') }}</textarea></label>
<label class="check section"><input type="checkbox" name="confirmed" value="1" required> Confirmo que ya entregué {{ $money($credit->capital_cents) }} a la persona indicada, desde esta caja de prueba.</label><button class="btn primary" type="submit">Confirmar entrega de capital</button>
</form>
@endif
@endsection
