@extends('cajas.layout')
@section('title', 'Accesos')
@section('content')
    <a class="back" href="{{ route('cajas.settings') }}">← Configuración</a><div class="page-head"><div><p class="eyebrow">Accesos por persona</p><h1>{{ $member->name }}</h1><p class="muted">Estos alcances se combinan con los permisos de su perfil.</p></div></div>
    <div class="notice subtle">Activa la consulta de cada caja y las acciones de prueba permitidas. Cada acción también necesita su permiso de perfil; no concede acceso a otras cajas.</div>
    <form method="post" action="{{ route('cajas.access.update', $member) }}">@csrf @method('PUT')
        <div class="two-cols"><fieldset class="panel pad"><legend>Cajas asignadas</legend>@forelse($accounts as $account)<div class="scope-row"><label class="check check-row"><input type="checkbox" name="account_ids[]" value="{{ $account->id }}" @checked(in_array($account->id, $errors->any() ? old('account_ids', []) : $accountIds))><span>{{ $account->name }}<small>{{ $account->kind === 'bank' ? 'Cuenta bancaria' : 'Efectivo' }}{{ $account->active ? '' : ' · Inactiva' }}</small></span></label>
            @if($account->is_test)
                <div class="scope-options">
                @foreach(['income' => 'Ingresar', 'expense' => 'Egresar', 'send' => 'Enviar', 'receive' => 'Aceptar', 'adjust' => 'Autorizar ajustes de caja', 'count' => 'Registrar revisiones de saldo', 'close' => 'Autorizar revisiones de saldo'] as $action => $label)
                    @php($checked = $errors->any() ? in_array($account->id, old($action.'_account_ids', [])) : (bool) ($cashScopes->get($account->id)?->{'can_'.$action} ?? false))
                    <label class="check"><input type="checkbox" name="{{ $action }}_account_ids[]" value="{{ $account->id }}" @checked($checked)> {{ $label }}</label>
                @endforeach
                </div>
            @endif
            </div>@empty<p class="muted">Primero crea las cajas en Configuración.</p>@endforelse</fieldset>
        <fieldset class="panel pad"><legend>Operadores asignados</legend>@forelse($operators as $operator)<label class="check check-row"><input type="checkbox" name="operator_ids[]" value="{{ $operator->id }}" @checked(in_array($operator->id, $errors->any() ? old('operator_ids', []) : $operatorIds))><span>{{ $operator->name }}{{ $operator->status === 'active' ? '' : ' · Inactivo' }}</span></label>@empty<p class="muted">No hay operadores registrados.</p>@endforelse</fieldset></div>
        <section class="panel pad section"><h2>Fuentes permitidas por su perfil</h2><div class="row section"><span class="badge">Préstamos: {{ $member->can('cajas.collections.loans.view') ? 'permitido' : 'sin permiso' }}</span><span class="badge">Naboo: {{ $member->can('cajas.collections.naboo.view') ? 'permitido · conexión futura' : 'sin permiso' }}</span></div><label class="section">Motivo del cambio<textarea name="reason" maxlength="500" rows="2" required placeholder="Ej. Asignación inicial de las cajas de oficina">{{ old('reason') }}</textarea></label><div class="row section"><button class="btn primary" type="submit">Guardar accesos</button><a class="btn" href="{{ route('cajas.settings') }}">Volver</a></div></section>
    </form>
@endsection
