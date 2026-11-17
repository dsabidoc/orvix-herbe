@include('cajas.partials.dialog-header', ['title' => 'Nuevo crédito pendiente', 'context' => $cut->operator->name.' · Corte '.$cut->cut_on->format('d/m/Y')])
<div class="cash-dialog-body">
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.cuts.credit-form-fields', ['modal' => true])
</div>
