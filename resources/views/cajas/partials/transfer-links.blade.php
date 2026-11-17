@php
    $user = auth()->user();
    $available = \App\Domain\Cajas\CajasSandbox::enabled() && $operation->kind === 'transfer';
    $source = $operation->source;
    $destination = $operation->destination;
@endphp
@if($available && $operation->status === 'prepared' && $source->active && $source->is_test && $user->can('cajas.transfers.send') && $source->allows($user, 'send'))
    <a class="btn primary" data-cash-dialog href="{{ route('cajas.operations.transition-form', [$operation, 'deliver']) }}">Confirmar entrega</a>
@elseif($available && in_array($operation->status, ['delivered', 'disputed'], true) && $destination?->active && $destination->is_test && $user->can('cajas.transfers.receive') && $destination->allows($user, 'receive'))
    <a class="btn primary" data-cash-dialog href="{{ route('cajas.operations.transition-form', [$operation, 'accept']) }}">Revisar recepción</a>
@endif
