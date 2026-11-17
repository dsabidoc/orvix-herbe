@if($record->status === 'closed' && $record->difference_cents !== 0 && \App\Domain\Cajas\CajasSandbox::enabled())
@php($differenceState = app(\App\Domain\Cajas\CashDifferenceService::class)->state($record))
@php($differenceHistory = $record->differenceResolutions()->with(['creator', 'resolver'])->orderBy('id')->get())
<section class="panel pad section"><div class="section-head"><h2>Aclaración de la diferencia</h2><span class="badge soft">{{ ['unresolved' => 'Por aclarar', 'pending' => 'Pendiente de Herbe', 'resolved' => 'Diferencia resuelta', 'review' => 'Requiere nueva revisión'][$differenceState] }}</span></div>
<p class="muted">La diferencia original se conserva. Resolver su causa no genera otro movimiento de dinero.</p>
@if($differenceState === 'review')<div class="notice section">Cambió una corrección que respaldaba la resolución. Se necesita una nueva aclaración; la anterior permanece en el historial.</div>@endif
@if(in_array($differenceState, ['unresolved', 'review']) && $account->active && auth()->user()->can('cajas.differences.request') && $account->allows(auth()->user(), 'count'))<a class="btn primary section" href="{{ route('cajas.differences.create', [$account, $record]) }}">Aclarar diferencia</a>@endif
@foreach($differenceHistory as $item)<div class="list-row"><div class="grow"><strong>{{ $item->statusLabel() }}</strong><p class="small muted">{{ $item->creator->name }} · {{ $item->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>{{ $item->reason }}</p></div><a class="btn" href="{{ route('cajas.differences.show', [$account, $record, $item]) }}">{{ $item->status !== 'pending' ? 'Ver resolución' : ((auth()->id() !== $item->created_by && $account->active && auth()->user()->can('cajas.differences.resolve') && $account->allows(auth()->user(), 'close')) ? 'Revisar aclaración' : 'Ver seguimiento') }}</a></div>@endforeach
</section>
@endif
