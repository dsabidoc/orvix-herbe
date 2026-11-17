<div class="cards">
    @forelse($accounts as $account)
        <a class="account-card" href="{{ route('cajas.accounts.show', $account) }}">
            <div class="row between"><span class="eyebrow">{{ $account->kind === 'bank' ? 'Cuenta bancaria' : 'Efectivo' }}</span><span class="badge">{{ $account->active ? $account->currency : 'Inactiva' }}</span></div>
            <h2>{{ $account->name }}</h2>
            @if($account->is_test && \App\Domain\Cajas\CajasSandbox::enabled())
                <div class="balance-placeholder money">{{ \App\Support\Money::mxn(\App\Support\Money::decimal($account->balanceCents())) }}</div>
                <p class="muted small">Disponible de prueba · Ver caja <span aria-hidden="true">↗</span></p>
            @else
                <div class="balance-placeholder">Saldo sin habilitar</div>
                <p class="muted small">Configuración inicial · Ver caja <span aria-hidden="true">↗</span></p>
            @endif
        </a>
    @empty
        <div class="empty panel"><div class="empty-symbol" aria-hidden="true">▤</div><h2>Aún no tienes cajas asignadas</h2><p>Cuando te asignen una caja de efectivo o una cuenta bancaria, aparecerá aquí.</p></div>
    @endforelse
</div>
