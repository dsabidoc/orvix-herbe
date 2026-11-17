@extends('cajas.layout')
@section('title', 'Corte '.$cut->cut_on->format('d/m/Y'))
@section('content')
@php
    $money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal((int) $cents));
    $applied = app(\App\Domain\Cajas\CashLoanRehearsal::class)->applied($cut);
    $integrated = $cut->items->contains(fn ($item) => $item->installment?->loan?->loan_id !== null);
    $editable = $cut->status === 'draft' && ! $cut->custody_locked_at && ! $correctionPending && auth()->user()->can('cajas.cuts.prepare');
    $canReceive = in_array($cut->status, ['draft', 'observed', 'approved_test']) && ! $correctionPending && $custody['remaining'] > 0 && auth()->user()->can('cajas.receipts.record');
    $canSubmit = ! $correctionPending && in_array($cut->status, ['draft', 'observed']) && $custody['reconciled'] && auth()->user()->can('cajas.cuts.submit') && auth()->user()->can('cajas.pending-loans.view');
@endphp
    <div class="cash-cut-page">
        <a class="back" href="{{ route('cajas.collections') }}">← Cobros</a>
        <div class="page-head page-head--actions">
            <div>
                <p class="eyebrow">Corte de prueba · {{ $cut->cut_on->format('d/m/Y') }}</p>
                <div class="row cash-cut-title"><h1>{{ $cut->operator->name }}</h1>@include('cajas.cuts.status')</div>
            </div>
            <div class="page-actions">
                @if($canReceive)<a class="btn primary" data-cash-dialog href="{{ route('cajas.cuts.receipt-form', $cut) }}">Recibir dinero</a>@endif
                @if($canSubmit)<a class="btn primary" data-cash-dialog href="{{ route('cajas.cuts.submit-form', $cut) }}">{{ $cut->status === 'observed' ? 'Reenviar a Herbe' : 'Enviar a Herbe' }}</a>@endif
                @if(!$correctionPending && $cut->status === 'submitted' && auth()->user()->can('cajas.cuts.review') && auth()->user()->can('cajas.pending-loans.view'))<a class="btn primary" data-cash-dialog href="{{ route('cajas.cuts.review-form', $cut) }}">Revisar corte</a>@endif
                @if($editable && auth()->user()->can('cajas.payments.report'))<a class="btn {{ $canReceive || $canSubmit ? '' : 'primary' }}" data-cash-dialog href="{{ route('cajas.cuts.letters-form', $cut) }}"><span class="cash-action-plus" aria-hidden="true">+</span> Agregar letras</a>@endif
                @if($editable && auth()->user()->can('cajas.advances.report'))<a class="btn" data-cash-dialog href="{{ route('cajas.cuts.advance-form', $cut) }}">Adelanto a capital</a>@endif
                @if($editable && auth()->user()->can('cajas.pending-loans.create') && auth()->user()->can('cajas.pending-loans.view'))<a class="btn" data-cash-dialog href="{{ route('cajas.cuts.credit-form', $cut) }}"><span class="cash-action-plus" aria-hidden="true">+</span> Crédito pendiente</a>@endif
            </div>
        </div>
        @if($integrated)<div class="notice subtle section">{{ $applied ? 'Los cobros quedaron aplicados en la copia de Préstamos. Una corrección posterior requiere revisar ambos registros.' : 'Ensayo con Préstamos. Al autorizar, Herbe aplicará estos cobros en la copia aislada.' }}</div>@endif
        @if($correctionPending)<div class="notice subtle section"><strong>Corrección pendiente de Herbe.</strong><p>Los registros originales siguen vigentes. Resuelve la solicitud antes de recibir, enviar o autorizar el corte.</p></div>@endif
        <div class="panel cash-account-summary cash-cut-summary">
            <section aria-labelledby="cash-cut-total-title">
                <h2 id="cash-cut-total-title">Cobros reportados</h2>
                <p class="cash-account-balance money">{{ $money($totalCents) }}</p>
                <p class="small muted">{{ $cut->items->count() }} conceptos · {{ $applied ? 'Aplicados en la copia de Préstamos' : 'Sin aplicar a préstamos' }}</p>
            </section>
            <div class="cash-account-transit cash-cut-figures">
                <section aria-labelledby="cash-cut-received-title">
                    <h2 id="cash-cut-received-title">Dinero recibido</h2>
                    <p class="money cash-cut-figure">{{ $custody['received'] > 0 ? $money($custody['received']) : 'Sin recibir' }}</p>
                    <p class="small muted">{{ $custody['reconciled'] ? 'Entrega conciliada de prueba' : 'Solo el dinero contado entra a caja' }}</p>
                </section>
                @can('cajas.pending-loans.view')
                    <section aria-labelledby="cash-cut-credit-title">
                        <h2 id="cash-cut-credit-title">Créditos pendientes</h2>
                        <p class="money cash-cut-figure">{{ $money($pending->where('status', '!=', 'cancelled')->filter(fn ($credit) => !$credit->conversion && !$credit->fundingCancelled())->sum('capital_cents')) }}</p>
                        <p class="small muted">{{ $custody['offset'] > 0 || $pending->contains(fn ($credit) => $credit->disbursement !== null) ? 'Condiciones y entregas de capital de prueba' : 'Condiciones propuestas · Sin desembolso' }}</p>
                    </section>
                @endcan
            </div>
        </div>
        <section id="cut-items" class="section panel cash-history" aria-labelledby="cash-cut-items-title">
            <div class="section-head"><h2 id="cash-cut-items-title">Conceptos del corte</h2><span class="badge">{{ $applied ? 'Aplicados · Ensayo' : 'Reportados, sin aplicar' }}</span></div>
            @forelse($cut->items as $item)
                <div class="cut-item"><div class="grow"><strong class="cash-cut-concept-identity">{{ $item->installment?->loan?->paymentLabel() ?? 'Sin modelo' }}</strong><strong>{{ $item->snapshot['client_name'] }} · Pagaré {{ $item->snapshot['number'] }}</strong><p class="small muted">{{ $item->snapshot['folio'] }} · Vence {{ \Carbon\CarbonImmutable::parse($item->snapshot['due_date'])->format('d/m/Y') }}</p><span class="badge">{{ ['ordinary' => 'Pago de letra', 'capital' => 'Adelanto · Solo capital', 'capital_interest' => 'Adelanto · Con intereses', 'general_capital' => 'Capital · Últimas letras'][$item->kind] }}</span></div><strong>{{ $money($item->amount_cents) }}</strong>
                @if($editable && auth()->user()->can('cajas.payments.report'))<details class="remove-concept"><summary>Retirar</summary><form method="post" action="{{ route('cajas.cuts.remove', [$cut, 'item', $item->id]) }}">@include('cajas.cuts.command-fields')<label>Motivo<input name="reason" required maxlength="500"></label><button class="btn" type="submit">Retirar concepto</button></form></details>@endif
                @if(!$applied && $cut->custody_locked_at && !$correctionPending && auth()->user()->can('cajas.corrections.request') && auth()->user()->can('cajas.pending-loans.view'))<a class="btn" href="{{ route('cajas.corrections.create', [$cut, 'item', $item->id]) }}">Corregir concepto</a>@endif
                </div>
            @empty<div class="empty"><h2>Aún no has agregado cobros</h2><p>Marca las letras de la lista impresa. Los adelantos futuros se guardan a capital por defecto.</p></div>@endforelse
        </section>
        @can('cajas.pending-loans.view')
        <div id="cut-credits" class="section-head"><h2>Créditos del corte</h2><span class="small muted">Pendientes y vigentes de prueba</span></div>
        <div class="panel">
        @forelse($pending as $credit)
            <div class="pad credit-record"><div class="row between"><h2>{{ $credit->client_name }}</h2><strong>{{ $money($credit->capital_cents) }}</strong></div><div class="row section"><span class="badge">{{ $credit->fundingCancelled() ? 'Cancelado · Prueba' : ($credit->conversion ? 'Vigente · Prueba' : ['draft' => 'Condiciones por autorizar', 'authorized' => 'Condiciones autorizadas · Pendiente de Herbe', 'cancelled' => 'Retirado sin desembolso'][$credit->status]) }}</span><span class="badge">{{ $credit->fundingCancelled() ? 'Entrega cancelada' : ($credit->disbursement ? 'Capital entregado desde caja · Prueba' : ($offsets->contains('cash_pending_loan_id', $credit->id) ? 'Compensado · Prueba' : ($credit->planned_funding === 'offset' ? 'Compensación propuesta' : 'Salida de caja propuesta'))) }}</span></div><p class="small muted">{{ $credit->terms['term_months'] }} meses · {{ $credit->terms['monthly_rate_percent'] }}% mensual · Primer pago {{ $credit->terms['first_payment_date'] }}</p>
            <a class="btn section" href="{{ route('cajas.credits.show', [$cut, $credit->public_id]) }}">{{ $credit->conversion ? 'Ver préstamo de prueba' : 'Ver crédito pendiente' }}</a>
            <details class="section"><summary>Ver condiciones y calendario</summary>@if($credit->notes)<p class="muted">{{ $credit->notes }}</p>@endif @include('cajas.cuts.schedule', ['schedule' => $credit->schedule, 'terms' => $credit->terms])</details>
            @if($editable && $credit->status === 'draft' && auth()->user()->can('cajas.pending-loans.authorize'))<form class="section" method="post" action="{{ route('cajas.cuts.credit-authorize', [$cut, $credit->id]) }}">@include('cajas.cuts.command-fields')<label class="check"><input type="checkbox" name="confirmed" value="1" required> Revisé estas condiciones y el calendario.</label><button class="btn" type="submit">Autorizar condiciones</button></form>@endif
            @if($editable && $credit->status !== 'cancelled' && auth()->user()->can('cajas.pending-loans.create'))<details class="section"><summary>Retirar crédito de la preparación</summary><form method="post" action="{{ route('cajas.cuts.remove', [$cut, 'credit', $credit->id]) }}">@include('cajas.cuts.command-fields')<label>Motivo<input name="reason" required maxlength="500"></label><button class="btn" type="submit">Retirar crédito</button></form></details>@endif</div>
        @empty<div class="empty"><p>Agrega los nuevos créditos de la lista con sus condiciones de pago.</p></div>@endforelse
        </div>
        @if($offsetCents > 0 && ! $cut->custody_locked_at)<div class="notice subtle section"><strong>Neto estimado si se ejecutan las compensaciones propuestas: {{ $money($totalCents - $offsetCents) }}</strong><p>Es una estimación. No se ha compensado, recibido ni entregado dinero.</p>@if($totalCents < $offsetCents)<p>Las propuestas requieren fondos adicionales; no equivalen a una recepción negativa.</p>@endif</div>@endif
        @endcan
        @include('cajas.cuts.receipt-review')
        @if(!$applied && $cut->custody_locked_at && !$correctionPending && auth()->user()->can('cajas.corrections.request') && auth()->user()->can('cajas.pending-loans.view'))<div class="section"><a class="btn" href="{{ route('cajas.corrections.create', [$cut, 'item', 0]) }}">Proponer concepto omitido</a><p class="small muted">Aquí puedes corregir letras, adelantos e importes de recibos. Para corregir o cancelar una entrega de capital o compensación, entra al crédito correspondiente. Sus condiciones se conservan.</p></div>@endif
        @if($corrections->isNotEmpty())<section class="panel pad section"><h2>Historial de correcciones</h2>@foreach($corrections as $correction)<a class="movement-row" href="{{ route('cajas.corrections.show', [$cut, $correction]) }}"><div class="grow"><strong>{{ $correction->statusLabel() }}</strong><p class="small muted">{{ $correction->reason }}</p><span class="small muted">{{ $correction->creator?->name }} · {{ $correction->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</span></div><span>Ver antes y después →</span></a>@endforeach</section>@endif
        <details class="section panel pad"><summary>Historial de la preparación</summary>@foreach($events as $event)<div class="list-row"><div><strong>{{ ['disburse_credit' => 'Capital entregado desde caja de prueba', 'convert_credit' => 'Crédito convertido a vigente de prueba', 'opened' => 'Corte abierto', 'letters' => 'Letras agregadas', 'general_advance' => 'Adelanto agregado', 'pending_loan' => 'Crédito pendiente guardado', 'authorize_credit' => 'Condiciones de crédito autorizadas', 'remove_item' => 'Concepto retirado', 'remove_credit' => 'Crédito retirado', 'receive' => 'Entrega registrada', 'submit' => 'Enviado a Herbe', 'observe' => 'Aclaración solicitada', 'approve' => 'Autorizado en prueba'][str_replace('cajas.cut.', '', $event->action)] ?? 'Actualización' }}</strong><p class="small muted">{{ $event->user?->name }} · {{ $event->created_at->timezone('America/Merida')->format('d/m/Y H:i') }}</p>@if($event->reason)<p class="small muted">{{ $event->reason }}</p>@endif</div></div>@endforeach</details>
        @include('cajas.partials.dialog')
    </div>
@endsection
