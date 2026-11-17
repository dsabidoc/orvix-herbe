@if(\App\Domain\Cajas\CajasSandbox::enabled() && auth()->user()->can('cajas.accounts.view') && (auth()->user()->can('cajas.differences.request') || auth()->user()->can('cajas.differences.resolve')))
    @php
        $user = auth()->user();
        $differenceCounts = \App\Models\CashReconciliation::where('status', 'closed')->where('difference_cents', '!=', 0)
            ->whereHas('account', fn ($query) => $query->visibleTo($user)->where('is_test', true))
            ->with(['account', 'differenceResolutions'])->latest('id')->get()
            ->map(function ($count) use ($user) {
                $state = app(\App\Domain\Cajas\CashDifferenceService::class)->state($count);
                $pending = $count->differenceResolutions->firstWhere('status', 'pending');
                $canReview = $pending && $pending->created_by !== $user->id && $count->account->active
                    && $user->can('cajas.differences.resolve') && $count->account->allows($user, 'close');
                $canRequest = in_array($state, ['unresolved', 'review']) && $count->account->active
                    && $user->can('cajas.differences.request') && $count->account->allows($user, 'count');

                return compact('count', 'state', 'pending', 'canReview', 'canRequest');
            })->reject(fn ($item) => $item['state'] === 'resolved');
    @endphp
    @if($differenceCounts->isNotEmpty())
        <div class="section-head"><h2>Diferencias de saldo</h2></div>
        <section class="panel">
            @foreach($differenceCounts as $item)
                @php
                    ['count' => $count, 'state' => $state, 'pending' => $pending, 'canReview' => $canReview, 'canRequest' => $canRequest] = $item;
                    $url = $pending ? route('cajas.differences.show', [$count->account, $count, $pending]) : route('cajas.reconciliations.show', [$count->account, $count]);
                @endphp
                <a class="movement-row" href="{{ $url }}">
                    <div class="grow">
                        <strong>{{ $count->account->name }} · Revisión de saldo #{{ $count->id }}</strong>
                        <p class="small muted">{{ $count->difference_cents < 0 ? 'Faltante' : 'Sobrante' }} de {{ \App\Support\Money::mxn(\App\Support\Money::decimal(abs($count->difference_cents))) }} · {{ ['unresolved' => 'Por aclarar', 'pending' => 'Pendiente de Herbe', 'review' => 'Requiere nueva revisión'][$state] }}</p>
                    </div>
                    <span>{{ $canReview ? 'Revisar aclaración' : ($canRequest ? 'Ver revisión' : ($pending ? 'Ver seguimiento' : 'Ver detalle')) }} →</span>
                </a>
            @endforeach
        </section>
    @endif
@endif
