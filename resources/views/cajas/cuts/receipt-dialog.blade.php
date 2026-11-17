@php($pendingAmount = \App\Support\Money::mxn(\App\Support\Money::decimal((int) $custody['remaining'])))
@include('cajas.partials.dialog-header', ['title' => 'Recibir dinero', 'context' => $cut->operator->name.' · Corte '.$cut->cut_on->format('d/m/Y')])
<div class="cash-dialog-body">
    <div class="dialog-balance"><span>Pendiente por conciliar</span><strong>{{ $pendingAmount }}</strong></div>
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.cuts.receipt-form-fields', ['modal' => true])
</div>
