@php($isNew = ! isset($account))
<div class="form-grid">
    <label>Nombre de la caja<input name="name" value="{{ $isNew ? old('name') : $account->name }}" placeholder="Ej. Efectivo oficina" maxlength="100" required></label>
    <label>Tipo<select name="kind" required><option value="cash" @selected(($isNew ? old('kind', 'cash') : $account->kind) === 'cash')>Efectivo</option><option value="bank" @selected(($isNew ? old('kind') : $account->kind) === 'bank')>Cuenta bancaria</option></select></label>
    <label>Moneda<select name="currency"><option value="MXN">MXN · Peso mexicano</option></select></label>
</div>
@can('cajas.access.manage')
<fieldset><legend>Fuentes de cobro</legend><div class="row"><label class="check"><input type="checkbox" name="loans_enabled" value="1" @checked($isNew ? old('loans_enabled') : $account->loans_enabled)> Préstamos</label><label class="check"><input type="checkbox" name="naboo_enabled" value="1" @checked($isNew ? old('naboo_enabled') : $account->naboo_enabled)> Naboo <span class="muted small">(conexión futura)</span></label></div></fieldset>
@else
<p class="small muted">La habilitación de fuentes requiere también permiso para administrar accesos.</p>
@endcan
<input type="hidden" name="active" value="0">
<label class="check"><input type="checkbox" name="active" value="1" @checked($isNew ? old('active', true) : $account->active)> Caja activa</label>
<p class="small muted">Solo configura la caja. No crea saldos, movimientos ni asignaciones automáticas.</p>
<button class="btn primary" type="submit">{{ $isNew ? 'Crear caja' : 'Guardar configuración' }}</button>
