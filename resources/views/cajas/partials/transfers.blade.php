<div class="panel">
    @foreach($transfers as $transfer)
        <div class="list-row transfer-row">
            <a class="grow" href="{{ route('cajas.operations.show', $transfer) }}">
                @if($showTransferStatusBadge ?? false)
                    <span class="cash-transfer-title"><strong>Traspaso</strong><span class="badge cash-status-pending">{{ $transfer->statusLabel() }}</span></span>
                @else
                    <strong>Traspaso · {{ $transfer->statusLabel() }}</strong>
                @endif
                <p class="small muted">{{ $transfer->reason }}</p>
            </a>
            <strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($transfer->amount_cents)) }}</strong>
            @include('cajas.partials.transfer-links', ['operation' => $transfer])
        </div>
    @endforeach
</div>
