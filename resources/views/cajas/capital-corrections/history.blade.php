@if(!$effective['cancelled'] && !$cut->hasPendingCorrection())
@can('cajas.disbursements.correct-request')<div class="row section"><a class="btn" href="{{ route('cajas.capital-corrections.create', [$cut, $credit->public_id, 'correct']) }}">Solicitar corrección de entrega</a><a class="btn" href="{{ route('cajas.capital-corrections.create', [$cut, $credit->public_id, 'cancel']) }}">Solicitar cancelación de entrega</a></div>@endcan
@endif
@if($effective['cancelled'])<div class="notice subtle section">Esta entrega quedó cancelada y no se volverá a desembolsar ni convertir. Para una nueva operación, prepara otro crédito pendiente.</div>@endif
@php($capitalHistory = $disbursement->corrections()->with(['creator', 'resolver'])->orderBy('id')->get())
@if($capitalHistory->isNotEmpty())
<section class="panel pad section"><h2>Historial de la entrega</h2><details class="section"><summary>Comprobante original conservado</summary><p>{{ $disbursement->account->name }} · {{ $disbursement->delivered_on->format('d/m/Y') }}</p><p>{{ $disbursement->recipient_name }} · {{ $disbursement->reference }}</p></details>
@foreach($capitalHistory as $item)<div class="list-row"><div class="grow"><strong>{{ $item->kind === 'cancel' ? 'Cancelación' : 'Corrección' }} · {{ $item->statusLabel() }}</strong><p class="small muted">{{ $item->creator?->name }} · {{ $item->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>{{ $item->reason }}</p></div>@if(auth()->user()->can('cajas.disbursements.correct-request') || auth()->user()->can('cajas.disbursements.correct-resolve'))<a class="btn" href="{{ route('cajas.capital-corrections.show', [$cut, $credit->public_id, $item]) }}">{{ $item->status === 'pending' ? 'Revisar solicitud' : 'Ver resolución' }}</a>@endif</div>@endforeach
</section>
@endif
