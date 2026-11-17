@include('cajas.partials.dialog-header', ['title' => 'Revisar saldo'])
<div class="cash-dialog-body">
    @if(isset($pending))
        <div class="dialog-balance"><span>Revisión de saldo #{{ $pending->id }}</span><strong>Pendiente de cierre</strong></div>
        <p class="muted">Ya enviaste un conteo a Herbe. Puedes consultar la revisión de saldo y su estado.</p>
        <div class="dialog-actions">
            <button class="btn" type="button" data-dialog-close>Cerrar</button>
            <a class="btn primary" href="{{ route('cajas.reconciliations.show', [$account, $pending]) }}">Ver revisión pendiente</a>
        </div>
    @else
        <div class="dialog-balance"><span>Saldo registrado</span><strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($snapshot['expected_cents'])) }}</strong></div>
        <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
        @include('cajas.partials.reconciliation-fields', ['modal' => true])
    @endif
</div>
