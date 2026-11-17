@extends('cajas.layout')
@section('title', 'Resolución de diferencia')
@section('content')
<a class="back" href="{{ route('cajas.reconciliations.show', [$account, $count]) }}">← Volver a la revisión de saldo</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">{{ $account->name }} · Revisión de saldo #{{ $count->id }}</p><h1>Resolución de diferencia</h1><p class="muted">Solicitó {{ $record->creator->name }} · {{ $record->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p></div><div class="page-actions">
    @if($record->status === 'pending' && auth()->id() !== $record->created_by && auth()->user()->can('cajas.differences.resolve') && $account->allows(auth()->user(), 'close') && $account->active)<a class="btn primary" href="#resolve-difference">Revisar aclaración</a>@endif
    <span class="badge soft">{{ $record->statusLabel() }}</span>
</div></div>
@if($differenceState === 'review')<div class="notice">Cambió una corrección vinculada. La autorización anterior se conserva como historia, pero la diferencia requiere una nueva revisión.</div>@endif
@include('cajas.differences.comparison')
<section class="panel pad section"><h2>Causa y respaldo</h2><p>{{ $record->reason }}</p><p class="muted">{{ $record->evidence }}</p></section>
@if($record->status === 'pending')
<div class="notice subtle section">La diferencia sigue pendiente hasta que Herbe autorice. Las correcciones vinculadas no se vuelven a aplicar.</div>
@if(auth()->id() !== $record->created_by && auth()->user()->can('cajas.differences.resolve') && $account->allows(auth()->user(), 'close') && $account->active)
<form id="resolve-difference" class="panel pad section" method="post" action="{{ route('cajas.differences.resolve', [$account, $count, $record, 'approve']) }}">@include('cajas.transfer-corrections.command-fields')<h2>Revisión de Herbe</h2><label class="section">Nota de resolución<textarea name="note" maxlength="1500" required>{{ old('note') }}</textarea></label><label class="check section"><input type="checkbox" name="confirmed" value="1" required> Verifiqué que la causa y los respaldos explican esta diferencia. Resolverla no vuelve a mover dinero.</label><div class="row section"><button class="btn primary" type="submit">Autorizar resolución</button><button class="btn" type="submit" formaction="{{ route('cajas.differences.resolve', [$account, $count, $record, 'reject']) }}">Rechazar aclaración</button></div></form>
@endif
@else
<section class="notice section"><strong>{{ $record->statusLabel() }}</strong><p>{{ $record->resolver->name }} · {{ $record->resolved_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>{{ $record->resolution_note }}</p><p>Esta resolución conserva sus evidencias originales. Consulta la revisión de saldo para ver el estado actual de la aclaración.</p></section>
@endif
@endsection
