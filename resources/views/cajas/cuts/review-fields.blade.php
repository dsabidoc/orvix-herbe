@php($money = fn ($cents) => \App\Support\Money::mxn(\App\Support\Money::decimal($cents)))
<dl class="cut-review-totals">
    <div><dt>Cobros reportados</dt><dd>{{ $money($custody['reported']) }}</dd></div>
    <div><dt>Dinero recibido</dt><dd>{{ $money($custody['received']) }}</dd></div>
    <div><dt>Compensaciones</dt><dd>{{ $money($custody['offset']) }}</dd></div>
</dl>
@if($sending)
    @if($observation)
        <div class="notice subtle section"><strong>Observación de Herbe</strong><p class="break">{{ $observation }}</p></div>
    @endif
    <form method="post" action="{{ route('cajas.cuts.submit', $cut) }}" data-dialog-reload-conflict>
        @include('cajas.cuts.command-fields')
        @if($cut->status === 'observed')
            <label class="section">Respuesta a Herbe
                <textarea name="note" rows="3" required maxlength="1000">{{ old('note') }}</textarea>
            </label>
        @endif
        <p class="muted section">Herbe recibirá esta versión para revisión. El efectivo seguirá en la caja donde se registró.</p>
        <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Revisé los cobros, los recibos y las compensaciones.</label>
        <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
            @if($modal ?? false)<button class="btn" type="button" data-dialog-close>Cancelar</button>@endif
            <button class="btn primary" type="submit">Confirmar envío</button>
        </div>
    </form>
@else
    @can('cajas.cuts.approve-test')
        <form class="section" method="post" action="{{ route('cajas.cuts.review', [$cut, 'approve']) }}" data-dialog-reload-conflict>
            @include('cajas.cuts.command-fields')
            <p class="muted">{{ $integrated ? 'Esta autorización aplica los pagos e intereses en la copia aislada.' : 'Esta autorización valida el corte ficticio.' }}</p>
            <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Revisé las letras, los adelantos, los créditos pendientes y las entregas.</label>
            <div class="row section"><button class="btn primary" type="submit">Autorizar corte de prueba</button></div>
        </form>
    @endcan
    <details class="loan-group section" @cannot('cajas.cuts.approve-test') open @endcannot>
        <summary>Pedir una aclaración</summary>
        <form class="pad" method="post" action="{{ route('cajas.cuts.review', [$cut, 'observe']) }}" data-dialog-reload-conflict>
            @include('cajas.cuts.command-fields')
            <label>¿Qué necesita aclarar Adriana?
                <textarea name="note" rows="3" maxlength="1000" required>{{ old('note') }}</textarea>
            </label>
            <label class="check section"><input type="checkbox" name="confirmed" value="1" required> Enviar la observación a Adriana para que responda.</label>
            <div class="row section"><button class="btn" type="submit">Solicitar aclaración</button></div>
        </form>
    </details>
@endif
