@extends('cajas.layout')
@section('title', 'Revisión de saldo')
@section('content')
@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal($cents)))
<a class="back" href="{{ route('cajas.accounts.show', $account) }}">← {{ $account->name }}</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">Revisión de saldo #{{ $record->id }} · Prueba</p><h1>{{ $record->statusLabel() }}</h1><p class="muted">{{ $snapshot['account_name'] }} · {{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i:s') }}</p></div><div class="page-actions">
    @if($record->status === 'pending' && $account->active && auth()->id() !== $record->created_by && auth()->user()->can('cajas.reconciliations.close') && $account->allows(auth()->user(), 'close'))
        <a class="btn primary" href="#review-count">Revisar conteo</a>
    @elseif($record->status !== 'pending' && $account->active && auth()->user()->can('cajas.reconciliations.record') && $account->allows(auth()->user(), 'count'))
        <a class="btn primary" href="{{ route('cajas.reconciliations.create', $account) }}">Nueva revisión de saldo</a>
    @endif
</div></div>
@if($stale)<div class="notice">La caja cambió después del conteo. La revisión queda en el historial. Recházala o retírala y vuelve a contar.</div>@endif
@include('cajas.reconciliations.summary')
<section class="panel pad section"><h2>Resultado del conteo</h2>
    <div class="list-row"><span class="grow">{{ $snapshot['kind'] === 'bank' ? 'Saldo verificado en banco' : 'Efectivo contado' }}</span><strong>{{ $money($record->counted_cents) }}</strong></div>
    <div class="list-row"><span class="grow">{{ $record->difference_cents < 0 ? 'Faltante' : ($record->difference_cents > 0 ? 'Sobrante' : 'Sin diferencia') }}</span><strong>{{ $money(abs($record->difference_cents)) }}</strong></div>
    @if($record->difference_cents !== 0)<div class="notice subtle section">{{ $record->status === 'closed' ? 'Herbe cerró reconociendo esta diferencia.' : 'La diferencia necesita revisión de Herbe.' }} El cierre no generó ajustes. La diferencia original permanece aunque después se aclare su causa.</div>@endif
    <p class="section"><strong>Referencia:</strong> {{ $record->evidence }}</p><p><strong>Observación:</strong> {{ $record->note ?: 'Sin observación adicional.' }}</p>
</section>
@include('cajas.differences.history')
@if($record->status === 'pending' && $account->active)
    @if(auth()->id() !== $record->created_by && auth()->user()->can('cajas.reconciliations.close') && $account->allows(auth()->user(), 'close'))
        <form id="review-count" class="panel pad section" method="post" action="{{ route('cajas.reconciliations.resolve', [$account, $record, 'close']) }}">
            @csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <h2>Revisión de Herbe</h2><label class="section">Nota de revisión<textarea name="note" rows="2" maxlength="1000" required>{{ old('note') }}</textarea></label>
            @if($record->difference_cents !== 0)<label class="check section"><input type="checkbox" name="difference_confirmed" value="1"> Reconozco {{ $record->difference_cents < 0 ? 'el faltante' : 'el sobrante' }} de {{ $money(abs($record->difference_cents)) }}. Cerrar no lo corrige ni mueve dinero.</label>@endif
            <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Revisé el conteo y su referencia.</label>
            <div class="row section"><button class="btn primary" type="submit" @disabled($stale)>{{ $record->difference_cents === 0 ? 'Autorizar cierre' : 'Cerrar con diferencia' }}</button><button class="btn" type="submit" formaction="{{ route('cajas.reconciliations.resolve', [$account, $record, 'reject']) }}">Rechazar y pedir otro conteo</button></div>
        </form>
    @elseif(auth()->id() === $record->created_by && auth()->user()->can('cajas.reconciliations.record') && $account->allows(auth()->user(), 'count'))
        <section class="panel pad section"><h2>Pendiente de revisión</h2><p class="muted">Herbe puede aprobar la revisión o pedir otro conteo. El conteo original se conserva.</p>
        <details class="section"><summary>Me equivoqué o necesito volver a contar</summary><form method="post" action="{{ route('cajas.reconciliations.resolve', [$account, $record, 'withdraw']) }}">
            @csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label class="section">Motivo<textarea name="note" rows="2" maxlength="1000" required></textarea></label><label class="check section"><input type="checkbox" name="confirmed" value="1" required> Conservar este intento en el historial.</label><button class="btn section" type="submit">Retirar y volver a contar</button>
        </form></details></section>
    @endif
@endif
<section class="panel pad section"><h2>Historial de la revisión</h2><div class="list-row"><div class="grow"><strong>Conteo registrado · {{ $record->creator->name }}</strong><p class="small muted">{{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i:s') }} · Enviado a revisión</p></div></div>
    @if($record->resolved_at)<div class="list-row"><div class="grow"><strong>{{ $record->statusLabel() }} · {{ $record->resolver->name }}</strong><p class="small muted">{{ $record->resolved_at->timezone('America/Merida')->format('d/m/Y H:i:s') }}</p><p>{{ $record->resolution_note }}</p></div></div>@endif
    @if($record->previousClose)<a class="back section" href="{{ route('cajas.reconciliations.show', [$account, $record->previousClose]) }}">Ver cierre anterior #{{ $record->previousClose->id }} →</a>@endif
    <p class="small muted section">El cierre guarda el resultado del conteo. La caja sigue operando y los movimientos posteriores entran en la siguiente revisión de saldo.</p>
    <details class="section"><summary>Movimientos incluidos desde el cierre anterior ({{ count($snapshot['period_entries']) }})</summary>@forelse($snapshot['period_entries'] as $entry)<div class="list-row"><div class="grow"><strong>Movimiento #{{ $entry['operation_id'] }}</strong><p class="small muted">Fecha registrada: {{ $entry['operated_on'] }} · Asiento #{{ $entry['id'] }}</p></div><strong>{{ $money($entry['amount_cents']) }}</strong></div>@empty<p class="muted">Sin movimientos nuevos.</p>@endforelse</details>
</section>
@endsection
