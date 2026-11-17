@include('cajas.partials.dialog-header')
<div class="cash-dialog-body">
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.partials.transfer-fields', ['modal' => true])
</div>
