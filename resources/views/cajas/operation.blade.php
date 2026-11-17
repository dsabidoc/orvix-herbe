@extends('cajas.layout')
@section('title', $operation->kindLabel())
@section('content')
    @php
        $user = auth()->user();
        $displayCents = in_array($operation->kind, ['cut_adjustment', 'pending_disbursement', 'manual_adjustment']) ? (int) \App\Models\CashEntry::where('cash_operation_id', $operation->id)->sum('amount_cents') : $operation->amount_cents;
        $manualState = in_array($operation->kind, ['income', 'expense'], true) ? $operation->effectiveManualState() : null;
        if ($manualState) { $displayCents = $manualState['amount_cents']; }
        $transferState = $operation->kind === 'transfer' && $operation->status === 'accepted' ? $operation->effectiveTransferState() : null;
        $test = \App\Domain\Cajas\CajasSandbox::enabled();
        $canSend = $test && $operation->source->allows($user, 'send') && $user->can('cajas.transfers.send');
        $canCorrectTransfer = $transferState && ! $transferState['cancelled'] && $canSend
            && $user->can('cajas.transfers.correct-request')
            && ! $operation->transferCorrections()->where('status', 'pending')->exists();
        $canCorrectManual = $manualState && ! $manualState['cancelled'] && $test
            && $operation->status === 'posted' && $operation->destination_id === null
            && $operation->source->active && $operation->source->is_test
            && $user->can('cajas.manual.correct-request')
            && $user->can(\App\Domain\Cajas\CashMovementService::PERMISSIONS[$operation->kind])
            && $operation->source->allows($user, $operation->kind)
            && ! $operation->manualCorrections()->where('status', 'pending')->exists();
        $reverted = $manualState['cancelled'] ?? $transferState['cancelled'] ?? false;
        $reference = $manualState['reference'] ?? $transferState['reference'] ?? $operation->reason;
        $date = $manualState['operated_on'] ?? $operation->operated_on->toDateString();
    @endphp
    <a class="back" href="{{ route('cajas.accounts') }}">← Mis cajas</a>
    <div class="page-head page-head--actions"><div><h1>{{ $operation->kindLabel() }}</h1></div><div class="page-actions">
        @include('cajas.partials.transfer-links')
        @if($canCorrectTransfer)
            <a class="btn" href="{{ route('cajas.transfer-corrections.create', [$operation, 'correct']) }}">Corregir traspaso</a>
            <a class="btn" href="{{ route('cajas.transfer-corrections.create', [$operation, 'cancel']) }}">Revertir</a>
        @endif
        @if($canCorrectManual)
            <a class="btn" href="{{ route('cajas.manual-corrections.create', [$operation, 'correct']) }}">Corregir</a>
            <a class="btn" href="{{ route('cajas.manual-corrections.create', [$operation, 'cancel']) }}">Revertir</a>
        @endif
    </div></div>
    <section class="panel pad cash-receipt">
        <div class="row between">
            <p class="money cash-receipt-amount">{{ \App\Support\Money::mxn(\App\Support\Money::decimal($displayCents)) }}</p>
            <span class="badge soft">{{ $reverted ? 'Revertido con autorización' : $operation->statusLabel() }}</span>
        </div>
        <p class="cash-receipt-boxes">{{ $operation->source->name }}@if($operation->destination) → {{ $operation->destination->name }}@endif</p>
        <p class="muted">{{ $reference }}</p>
        <details class="cash-receipt-details">
            <summary class="capture-summary">Datos del comprobante</summary>
            <dl class="cash-receipt-facts">
                <div><dt>Folio</dt><dd>{{ $operation->public_id }}</dd></div>
                <div><dt>{{ $operation->kind === 'transfer' ? 'Preparación' : 'Fecha' }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }}</dd></div>
                @if($operation->delivered_on)<div><dt>Entrega</dt><dd>{{ \Carbon\CarbonImmutable::parse($transferState['delivered_on'] ?? $operation->delivered_on)->format('d/m/Y') }}</dd></div>@endif
                @if($operation->received_on)<div><dt>Recepción</dt><dd>{{ \Carbon\CarbonImmutable::parse($transferState['received_on'] ?? $operation->received_on)->format('d/m/Y') }}</dd></div>@endif
            </dl>
        </details>
    </section>
    @if($manualState) @include('cajas.manual-corrections.history') @endif
    @if($operation->kind === 'manual_adjustment')
        @php($manualRecord = \App\Models\CashManualCorrection::where('adjustment_operation_id', $operation->id)->with('operation')->first())
        <div class="notice subtle section">Ajuste autorizado de un movimiento manual. Conserva el original y aplica solo la diferencia una vez.
        @if($manualRecord)<p class="section"><a class="btn" href="{{ route('cajas.operations.show', $manualRecord->operation) }}">Ver movimiento original e historial</a> <a class="btn" href="{{ route('cajas.manual-corrections.show', [$manualRecord->operation, $manualRecord]) }}">Ver autorización</a></p>@endif</div>
    @endif
    @if($operation->kind === 'transfer' && $operation->status === 'accepted') @include('cajas.transfer-corrections.history') @endif
    @if($operation->kind === 'transfer_reversal')<div class="notice subtle section">Reversión autorizada de un traspaso aceptado. Este movimiento registra la devolución completa entre las dos cajas.
@php($reversalRecord = \App\Models\CashTransferCorrection::where('reversal_operation_id', $operation->id)->first())
@if($reversalRecord)<p class="section"><a class="btn" href="{{ route('cajas.operations.show', $reversalRecord->transfer) }}">Ver traspaso original e historial</a></p>@endif</div>@endif
    @if($operation->kind === 'disbursement_correction')<div class="notice subtle section narrow">Corrección autorizada de la caja de origen. Conserva la salida original y registra su reclasificación; no es un nuevo desembolso.</div>@endif
    @if($operation->kind === 'disbursement_cancel')<div class="notice subtle section narrow">Cancelación autorizada: anulación de un registro erróneo o devolución completa confirmada. El egreso original permanece en el historial.</div>@endif
    @if($operation->kind === 'pending_disbursement')<div class="notice subtle section narrow">Capital entregado para un crédito pendiente de prueba. La salida ya quedó registrada; convertirlo a vigente reutiliza esta entrega sin volver a descontar dinero.</div>@endif
    @if($operation->kind === 'cut_adjustment')<div class="notice subtle section narrow">Ajuste autorizado por corrección de un recibo. Conserva el movimiento original; no es una entrega ni devolución física nueva.</div>@endif
    @if($operation->kind === 'transfer' && $operation->status === 'prepared' && $canSend)
        <details class="section narrow"><summary>Cancelar preparación</summary>@include('cajas.partials.transfer-action', ['action' => 'cancel', 'heading' => 'Cancelar sin mover dinero', 'button' => 'Cancelar preparación'])</details>
    @endif
    @if($events->isNotEmpty())
        <details class="section panel pad">
            <summary class="capture-summary">Historial de la operación</summary>
            @foreach($events as $event)
                <div class="list-row"><div class="grow">
                    <strong>{{ ['created' => 'Registro creado', 'deliver' => 'Entrega confirmada', 'accept' => 'Recepción aceptada', 'dispute' => 'Diferencia reportada', 'cancel' => 'Preparación cancelada'][str_replace('cajas.money.', '', $event->action)] ?? 'Actualización' }}</strong>
                    <p class="small muted">{{ $event->user?->name }} · {{ $event->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p>
                    @if($event->reason && $event->reason !== $reference)<p class="muted">{{ $event->reason }}</p>@endif
                </div></div>
            @endforeach
        </details>
    @endif
    @include('cajas.partials.dialog')
@endsection
