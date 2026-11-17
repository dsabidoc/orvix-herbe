@php($title = ['income' => 'Registrar ingreso', 'expense' => 'Registrar egreso', 'transfer' => 'Nuevo traspaso'][$kind])
@include('cajas.partials.dialog-header')
<div @class(['cash-dialog-body', 'cash-transfer-dialog' => $kind === 'transfer'])>
    <div class="dialog-balance"><span>Saldo disponible</span><strong>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($account->balanceCents())) }}</strong></div>
    <div class="errors" data-dialog-error role="alert" tabindex="-1" hidden></div>
    @include('cajas.partials.movement-fields', ['modal' => true])
</div>
