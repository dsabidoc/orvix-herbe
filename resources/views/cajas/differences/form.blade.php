@extends('cajas.layout')
@section('title', 'Aclarar diferencia')
@section('content')
@php($fmt = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal($cents)))
<a class="back" href="{{ route('cajas.reconciliations.show', [$account, $count]) }}">← Volver a la revisión de saldo</a>
<div class="page-head page-head--actions"><div><p class="eyebrow">{{ $account->name }} · Revisión de saldo #{{ $count->id }}</p><h1>Aclarar diferencia</h1><p>{{ $count->difference_cents < 0 ? 'Faltante' : 'Sobrante' }} original de <strong>{{ $fmt(abs($count->difference_cents)) }}</strong></p></div><div class="page-actions"><button class="btn primary" type="submit" form="preview-difference">Revisar aclaración</button></div></div>
<div class="row section"><a class="btn {{ $kind === 'corrections' ? 'primary' : '' }}" href="{{ route('cajas.differences.create', [$account, $count]) }}">Vincular correcciones</a><a class="btn {{ $kind === 'counting_error' ? 'primary' : '' }}" href="{{ route('cajas.differences.create', [$account, $count, 'kind' => 'counting_error']) }}">Fue un error de conteo</a></div>
<div class="notice subtle section">Aclarar no genera otro ingreso, egreso ni ajuste. La diferencia original permanece en el historial. Herbe verifica la causa y autoriza su resolución.</div>
<form id="preview-difference" class="panel pad section" method="post" action="{{ route('cajas.differences.preview', [$account, $count]) }}">@csrf
<input type="hidden" name="source_token" value="{{ $sourceToken }}"><input type="hidden" name="kind" value="{{ $kind }}">
@if($kind === 'corrections')
<h2>Correcciones ya autorizadas</h2><p class="muted">Selecciona las que explican la diferencia de esta revisión. Cada opción reúne todos los ajustes posteriores del mismo movimiento, con su efecto neto.</p>
@forelse($groups as $group)<label class="check check-row section"><input type="checkbox" name="correction_ids[]" value="{{ $group['key'] }}" data-cents="{{ $group['amount_cents'] }}" @checked(in_array($group['key'], old('correction_ids', [])))><span class="grow break">{{ $group['label'] }}<small>{{ count($group['entries']) }} ajustes vinculados</small></span><strong>{{ $group['amount_cents'] < 0 ? '−' : '+' }}{{ $fmt(abs($group['amount_cents'])) }}</strong></label>
@empty<div class="empty"><h3>No hay correcciones disponibles</h3><p>Primero registra y autoriza la corrección correspondiente desde el movimiento. Debe pertenecer a esta caja, haberse registrado después del conteo y estar disponible para esta revisión.</p></div>@endforelse
<div class="notice subtle section" aria-live="polite"><strong id="coverage">Selecciona los ajustes para ver lo que falta explicar.</strong><p class="small">Solo se puede enviar cuando explican la diferencia completa, sin ajustar dinero para cuadrarla.</p></div>
@else
<h2>Respaldar el error de conteo</h2><p class="muted">Explica qué se contó mal y vincula una revisión posterior de la misma caja que ya esté cerrada y sin diferencia. Herbe verificará la causa.</p>
<label class="section">Nueva revisión de saldo<select name="recount_id" required><option value="">Seleccionar</option>@foreach($recounts as $item)<option value="{{ $item->public_id }}" @selected(old('recount_id') === $item->public_id)>#{{ $item->id }} · {{ $item->created_at->timezone('America/Merida')->format('d/m/Y H:i') }} · {{ $fmt($item->counted_cents) }}</option>@endforeach</select></label>
@if($recounts->isEmpty())<p class="muted section">Primero registra otra revisión de saldo y solicita su cierre. El conteo debe cuadrar.</p>@endif
@endif
<label class="section">Qué causó la diferencia<textarea name="reason" maxlength="1500" rows="3" required>{{ old('reason') }}</textarea></label>
<label class="section">Evidencia o referencia del respaldo<textarea name="evidence" maxlength="1000" rows="2" required>{{ old('evidence') }}</textarea></label>
<label class="check section"><input type="checkbox" name="confirmed" value="1" required> Verifiqué que este respaldo explica la diferencia de esta revisión. No solicito otro movimiento de dinero.</label>
<button class="btn primary section" type="submit">Revisar aclaración</button></form>
@if($kind === 'corrections')<script>
(() => { const fields = [...document.querySelectorAll('[name="correction_ids[]"]')]; const diff = @json($count->difference_cents); const update = () => { const selected = fields.filter(x => x.checked).reduce((sum,x) => sum + Number(x.dataset.cents),0); const left = diff-selected; document.getElementById('coverage').textContent = left === 0 ? 'La diferencia queda explicada por completo.' : 'Diferencia pendiente de explicar: ' + (left/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'}); }; fields.forEach(x => x.addEventListener('change',update)); update(); })();
</script>@endif
@endsection
