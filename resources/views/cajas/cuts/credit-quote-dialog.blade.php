@include('cajas.partials.dialog-header', ['title' => 'Revisar calendario', 'context' => $quote['terms']['client_name']])
<div class="cash-dialog-body">
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.cuts.credit-quote-fields', ['modal' => true])
</div>
