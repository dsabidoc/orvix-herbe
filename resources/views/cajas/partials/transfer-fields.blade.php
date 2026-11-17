<div class="dialog-balance">
    <span>Importe del traspaso</span>
    <strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($operation->amount_cents)) }}</strong>
</div>
<p class="break"><strong>{{ $action === 'deliver' ? 'Destino' : 'Origen' }}:</strong>
    {{ $action === 'deliver' ? $operation->destination->name : $operation->source->name }}
</p>
<p class="muted">{{ $operation->reason }}</p>
<p class="muted">{{ $action === 'deliver' ? 'El importe saldrá de esta caja y quedará en tránsito hasta que se acepte.' : 'Al aceptar, el importe entrará al saldo disponible de esta caja.' }}</p>
@include('cajas.partials.transfer-action')
@if($action === 'accept' && $operation->status === 'delivered' && auth()->user()->can('cajas.transfers.dispute'))
    <details class="section">
        <summary>El dinero no coincide</summary>
        @include('cajas.partials.transfer-action', ['action' => 'dispute', 'heading' => 'Reportar diferencia', 'button' => 'Dejar en aclaración'])
    </details>
@endif
