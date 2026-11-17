@php($fmt = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents)))
<div class="two-cols section">@foreach(['before' => 'Antes', 'after' => 'Después de autorizar'] as $key => $heading)
<section class="panel pad"><h2>{{ $heading }}</h2><p class="money amount">{{ $fmt($proposal[$key]['cancelled'] ? 0 : $proposal[$key]['amount_cents']) }}</p><strong>{{ $proposal[$key]['cancelled'] ? 'Revertido' : $operation->kindLabel().' vigente' }}</strong><p>{{ $operation->source->name }}</p><p class="muted">Fecha: {{ $proposal[$key]['operated_on'] }}</p><p>{{ $proposal[$key]['reference'] }}</p></section>@endforeach</div>
<section class="panel pad section"><h2>Efecto en la caja</h2>
@if($proposal['delta_cents'] !== 0)<div class="list-row"><span class="grow">{{ $proposal['delta_cents'] > 0 ? 'Se agrega al saldo' : 'Se descuenta del saldo' }}</span><strong>{{ $proposal['delta_cents'] > 0 ? '+' : '−' }}{{ $fmt(abs($proposal['delta_cents'])) }}</strong></div><p class="muted section">Ajuste vinculado · {{ $proposal['effective_on'] }}.</p>
@else<p>Sin movimiento de dinero. Solo cambia la fecha o el concepto visible.</p>@endif
@if($kind === 'cancel')<p>{{ $proposal['cancel_type'] === 'returned' ? ($operation->kind === 'income' ? 'Devolución completa ya entregada desde esta caja.' : 'Devolución completa ya recibida en esta caja.') : 'Registro erróneo o duplicado: este movimiento no ocurrió.' }}</p>@endif
</section>
