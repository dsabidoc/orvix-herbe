@include('cajas.partials.dialog-header', ['title' => 'Crear corte', 'context' => 'Cobros · Prueba'])
<div class="cash-dialog-body">
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.cuts.create-fields', ['modal' => true])
</div>
