@extends('cajas.layout')
@section('title', 'Proponer corrección')
@section('content')
<a class="back" href="{{ route('cajas.cuts.show', $cut) }}">← Volver al corte</a>
<div class="page-head"><div><p class="eyebrow">{{ $cut->operator->name }} · {{ $cut->cut_on->format('d/m/Y') }}</p><h1>Proponer corrección</h1><p class="muted">Primero revisa el cambio. Herbe autorizará antes de aplicarlo.</p></div></div>
<form class="panel pad narrow" method="post" action="{{ route('cajas.corrections.preview', [$cut, $kind, $target]) }}">
@csrf<input type="hidden" name="version" value="{{ $cut->version }}">
@if($kind === 'receipt')
    <h2>Corregir importe de un recibo</h2><p class="muted">{{ $record->account->name }} · {{ $record->received_on->format('d/m/Y') }}</p>
    <p>Importe vigente: <strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($record->effectiveCents())) }}</strong></p>
    <label>Importe que realmente se recibió (MXN)<input name="amount" inputmode="decimal" value="{{ old('amount', \App\Support\Money::decimal($record->effectiveCents())) }}" required></label>
    <div class="notice subtle section">Úsalo para un error de captura del recibo. Una devolución física es otra operación y no se registra aquí. Se conserva la caja y la fecha original.</div>
@else
    <h2>{{ $record ? 'Corregir letra, importe o adelanto' : 'Agregar un concepto omitido' }}</h2>
    @if($record)<p class="muted">Actual: {{ $record->snapshot['client_name'] }} · Letra {{ $record->snapshot['number'] }} · {{ \App\Support\Money::mxn(\App\Support\Money::decimal($record->amount_cents)) }}</p>@endif
    <label>Letra correcta<select name="installment_id">@foreach($loans as $loan)<optgroup label="{{ $loan->client_name }} · {{ $loan->folio }}">@foreach($loan->installments as $letter)<option value="{{ $letter->id }}" @selected((int) old('installment_id', $record?->cash_test_installment_id) === $letter->id)>Letra {{ $letter->number }} · {{ $letter->due_date->format('d/m/Y') }}</option>@endforeach</optgroup>@endforeach</select></label>
    <label class="section">Importe para letra vencida o del mes (MXN)<input name="amount" inputmode="decimal" value="{{ old('amount', $record ? \App\Support\Money::decimal($record->amount_cents) : '') }}"><small class="muted">Vacío: saldo completo. Para adelantos se calcula el capital completo disponible, sin utilizar este campo.</small></label>
    @can('cajas.advances.include-interest')<label class="check section"><input type="checkbox" name="include_interest" value="1" @checked(old('include_interest', $record?->kind === 'capital_interest'))> Incluir intereses si la letra es de un mes futuro</label>@endcan
    @if($record)<label class="check section"><input type="checkbox" name="remove" value="1" @checked(old('remove'))> Retirar este concepto sin reemplazarlo</label>@endif
    <p class="footnote">La propuesta no libera todavía la letra original ni reserva la nueva. Se volverá a comprobar su disponibilidad al autorizar.</p>
@endif
<label class="section">Motivo de la corrección<textarea name="reason" rows="3" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<label class="section">Referencia o evidencia revisable<textarea name="reference" rows="2" maxlength="500" required placeholder="Lista, folio o comprobante que permite verificar el cambio">{{ old('reference') }}</textarea></label>
<button class="btn primary" type="submit">Revisar antes y después</button>
</form>
@endsection
