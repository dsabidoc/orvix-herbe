@extends('cajas.layout')
@section('title', $account->name)
@section('content')
    @php($canTest = $account->is_test && \App\Domain\Cajas\CajasSandbox::enabled())
    <div class="cash-account-page">
        @if($hasOtherAccounts)<a class="back" href="{{ route('cajas.accounts') }}">← Mis cajas</a>@endif
        <div class="page-head page-head--actions">
            <div><p class="eyebrow">{{ $account->kind === 'bank' ? 'Cuenta bancaria' : 'Efectivo' }} · {{ $account->currency }}</p><h1>{{ $account->name }}</h1></div>
            <div class="page-actions">
                @if($canTest && $account->active)
                    @foreach(['transfer' => ['Nuevo traspaso', 'send'], 'income' => ['Ingreso', 'income'], 'expense' => ['Egreso', 'expense']] as $kind => [$label, $scope])
                        @if(auth()->user()->can(\App\Domain\Cajas\CashMovementService::PERMISSIONS[$scope]) && $account->allows(auth()->user(), $scope))
                            <a class="btn {{ $kind === 'transfer' ? 'primary' : '' }}" data-cash-dialog href="{{ route('cajas.movements.create', [$account, $kind]) }}">
                                @if($kind === 'transfer')<span class="cash-action-plus" aria-hidden="true">+</span>@endif
                                {{ $label }}
                            </a>
                        @endif
                    @endforeach
                    @if(auth()->user()->can('cajas.reconciliations.record') && $account->allows(auth()->user(), 'count'))
                        <a class="btn" data-cash-dialog href="{{ route('cajas.reconciliations.create', $account) }}">Revisar saldo</a>
                    @endif
                @endif
            </div>
        </div>
        <div class="panel cash-account-summary">
            <section aria-labelledby="cash-balance-title">
                <h2 id="cash-balance-title">Saldo disponible</h2>
                <p class="{{ $canTest ? 'cash-account-balance money' : 'cash-account-disabled' }}">
                    {{ $canTest ? \App\Support\Money::mxn(\App\Support\Money::decimal($account->balanceCents())) : 'Sin habilitar' }}
                </p>
                <p class="small muted">{{ $canTest ? 'Importe ficticio disponible para esta prueba.' : 'Todavía no se ha registrado un saldo inicial en esta caja.' }}</p>
            </section>
            <section class="cash-account-transit" aria-labelledby="cash-transit-title">
                @if($canTest)
                    <h2 id="cash-transit-title">Dinero en tránsito</h2>
                    <dl>
                        <div><dt>Entregado, por aceptar</dt><dd>{{ \App\Support\Money::mxn(\App\Support\Money::decimal((int) $outgoingCents)) }}</dd></div>
                        <div><dt>Entrante, por revisar</dt><dd>{{ \App\Support\Money::mxn(\App\Support\Money::decimal((int) $incomingCents)) }}</dd></div>
                    </dl>
                    <p class="small muted">Los importes en tránsito no se suman al disponible.</p>
                @else
                    <h2 id="cash-transit-title">Fuentes de cobro</h2><p class="muted">Además de la fuente habilitada, necesitas permiso y operadores asignados.</p><div class="row"><span class="badge">Préstamos: {{ $account->loans_enabled ? 'habilitada' : 'deshabilitada' }}</span><span class="badge">Naboo: {{ $account->naboo_enabled ? 'prevista' : 'deshabilitada' }}</span></div>
                @endif
            </section>
        </div>
        @if($transfers->isNotEmpty())
            <section class="section cash-account-transfers" aria-labelledby="cash-transfers-title">
                <div class="section-head"><h2 id="cash-transfers-title">Traspasos pendientes</h2></div>
                @include('cajas.partials.transfers', ['showTransferStatusBadge' => true])
            </section>
        @endif
        <section class="section panel cash-history" aria-labelledby="cash-history-title">
            <div class="section-head"><h2 id="cash-history-title">Historial de movimientos</h2><span class="small muted">{{ $canTest ? 'Datos de prueba' : 'Próximamente' }}</span></div>
            @if($entries->isNotEmpty())
                <div class="cash-history-columns" aria-hidden="true"><span>Movimiento</span><span>Estado</span><span>Importe</span></div>
            @endif
            @forelse($entries as $entry)
                <a class="cash-history-row" href="{{ route('cajas.operations.show', $entry->operation) }}">
                    <div class="cash-history-detail">
                        <strong>{{ $entry->operation->kindLabel() }}</strong>
                        <span class="small muted">{{ $entry->operation->reason }}</span>
                        <time class="small muted" datetime="{{ $entry->operated_on->toDateString() }}">{{ $entry->operated_on->format('d/m/Y') }}</time>
                    </div>
                    <span class="badge cash-history-status {{ in_array($entry->operation->status, ['delivered', 'disputed'], true) ? 'cash-status-pending' : '' }}">{{ $entry->operation->statusLabel() }}</span>
                    <strong class="cash-history-amount {{ $entry->amount_cents > 0 ? 'positive' : '' }}">{{ $entry->amount_cents > 0 ? '+' : '−' }}{{ \App\Support\Money::mxn(\App\Support\Money::decimal(abs($entry->amount_cents))) }}</strong>
                </a>
            @empty
                <div class="empty"><h2>{{ $canTest ? 'Tu primer movimiento empieza aquí' : 'El dinero aún no se registra aquí' }}</h2><p>{{ $canTest ? 'Registra un ingreso ficticio para comenzar a probar. Los traspasos preparados todavía no afectan el saldo.' : 'Este espacio mostrará los ingresos, egresos y traspasos cuando se habilite la operación.' }}</p></div>
            @endforelse
            @if($entries->hasPages())<div class="row cash-history-pagination">@if($entries->previousPageUrl())<a class="btn" href="{{ $entries->previousPageUrl() }}">← Anteriores</a>@endif<span class="small muted">Página {{ $entries->currentPage() }} de {{ $entries->lastPage() }}</span>@if($entries->nextPageUrl())<a class="btn" href="{{ $entries->nextPageUrl() }}">Siguientes →</a>@endif</div>@endif
        </section>
        @if($canTest)
            <section class="section panel pad"><div class="section-head"><h2>Revisiones de saldo</h2>
            </div><p class="muted">Compara lo contado con el saldo registrado y envíalo a Herbe. Las diferencias se conservan sin ajustar dinero.</p>
            @forelse($reconciliations as $count)
                <a class="movement-row" href="{{ route('cajas.reconciliations.show', [$account, $count]) }}"><div class="grow"><strong>{{ $count->statusLabel() }}</strong><p class="small muted">{{ $count->created_at->timezone('America/Merida')->format('d/m/Y H:i:s') }} · {{ $count->creator->name }}</p>@if($count->status === 'closed' && $count->difference_cents !== 0)<span class="small muted">{{ ['unresolved' => 'Por aclarar', 'pending' => 'Aclaración pendiente de Herbe', 'resolved' => 'Diferencia resuelta', 'review' => 'Requiere nueva revisión'][app(\App\Domain\Cajas\CashDifferenceService::class)->state($count)] }}</span>@endif</div><strong>{{ $count->difference_cents < 0 ? 'Faltante' : ($count->difference_cents > 0 ? 'Sobrante' : 'Cuadrado') }}{{ $count->difference_cents !== 0 ? ' · '.\App\Support\Money::mxn(\App\Support\Money::decimal(abs($count->difference_cents))) : '' }}</strong></a>
            @empty<p class="muted section">Todavía no hay revisiones de saldo en esta caja.</p>@endforelse
            @if($reconciliations->hasPages())<div class="row section">@if($reconciliations->previousPageUrl())<a class="btn" href="{{ $reconciliations->previousPageUrl() }}">← Más recientes</a>@endif<span class="small muted">Página {{ $reconciliations->currentPage() }} de {{ $reconciliations->lastPage() }}</span>@if($reconciliations->nextPageUrl())<a class="btn" href="{{ $reconciliations->nextPageUrl() }}">Anteriores →</a>@endif</div>@endif
            </section>
        @endif
        @include('cajas.partials.dialog')
    </div>
@endsection
