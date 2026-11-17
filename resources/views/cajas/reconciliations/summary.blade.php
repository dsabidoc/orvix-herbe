@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal($cents)))
<div class="two-cols">
    <section class="panel pad"><p class="eyebrow">Saldo registrado al contar</p><div class="balance-placeholder large money">{{ $money($snapshot['expected_cents']) }}</div>
        <div class="list-row"><span class="grow">{{ $snapshot['previous_close_id'] ? 'Saldo del cierre anterior' : 'Saldo inicial de este historial' }}</span><strong>{{ $money($snapshot['opening_cents']) }}</strong></div>
        <div class="list-row"><span class="grow">Entradas desde entonces</span><strong>+{{ $money($snapshot['income_cents']) }}</strong></div>
        <div class="list-row"><span class="grow">Salidas desde entonces</span><strong>−{{ $money($snapshot['expense_cents']) }}</strong></div>
        <p class="small muted">Incluye {{ count($snapshot['period_entries']) }} movimientos nuevos. La siguiente revisión incluye los movimientos capturados después del conteo, aunque tengan una fecha anterior.</p>
    </section>
    <section class="panel pad"><h2>Dinero en tránsito</h2>
        <div class="list-row"><span class="grow">Entregado, por aceptar</span><strong>{{ $money($snapshot['outgoing_cents']) }}</strong></div>
        <div class="list-row"><span class="grow">Entrante, por revisar</span><strong>{{ $money($snapshot['incoming_cents']) }}</strong></div>
        <p class="muted">No sumes estos importes al conteo. Lo entregado ya salió de esta caja; lo entrante se registra cuando se acepta.</p>
        <p class="small muted">El cierre conserva también estos pendientes. No acepta ni cancela traspasos.</p>
    </section>
</div>
