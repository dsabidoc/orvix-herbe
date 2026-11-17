@php($offsetState = $offset->effectiveState())
<section class="notice section"><h2>{{ $offsetState['cancelled'] ? 'Compensación cancelada' : 'Compensación confirmada' }}</h2><p>{{ $money($offset->amount_cents) }} · {{ $offsetState['executed_on'] }} · {{ $offset->creator?->name }}</p><p>{{ $offsetState['reference'] }}</p><p class="small">El capital se compensó con los cobros del corte. No generó un ingreso ni un egreso de caja.</p></section>
@if(!$offsetState['cancelled'] && !$cut->hasPendingCorrection())
@can('cajas.offsets.correct-request')<div class="row section"><a class="btn" href="{{ route('cajas.offset-corrections.create', [$cut, $credit->public_id, 'correct']) }}">Solicitar corrección de compensación</a><a class="btn" href="{{ route('cajas.offset-corrections.create', [$cut, $credit->public_id, 'cancel']) }}">Solicitar cancelación de compensación</a></div>@endcan
@endif
@if($offsetState['cancelled'])<div class="notice subtle section">La cancelación retiró esta compensación del cálculo. El pendiente actual se consulta en el corte: se cubre al registrar una recepción real de prueba. Este crédito conserva su historial y no vuelve a ejecutarse.</div>@endif
@php($offsetHistory = $offset->corrections()->with(['creator', 'resolver'])->orderBy('id')->get())
@if($offsetHistory->isNotEmpty())
<section class="panel pad section"><h2>Historial de la compensación</h2><details class="section"><summary>Comprobante original conservado</summary><p>{{ $offset->executed_on->format('d/m/Y') }} · {{ $offset->reference }}</p><p>{{ $money($offset->amount_cents) }} · Crédito {{ $credit->client_name }}</p></details>
@foreach($offsetHistory as $item)<div class="list-row"><div class="grow"><strong>{{ $item->kind === 'cancel' ? 'Cancelación' : 'Corrección' }} · {{ $item->statusLabel() }}</strong><p class="small muted">{{ $item->creator?->name }} · {{ $item->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p><p>{{ $item->reason }}</p></div>@if(auth()->user()->can('cajas.offsets.correct-request') || auth()->user()->can('cajas.offsets.correct-resolve'))<a class="btn" href="{{ route('cajas.offset-corrections.show', [$cut, $credit->public_id, $item]) }}">{{ $item->status === 'pending' ? 'Revisar solicitud' : 'Ver resolución' }}</a>@endif</div>@endforeach
</section>
@endif
