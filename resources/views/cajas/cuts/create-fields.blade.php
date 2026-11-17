<form id="open-cut" @class(['panel pad narrow section' => !($modal ?? false)]) action="{{ route('cajas.cuts.store') }}" method="post">
    @csrf
    <label>Operador<select name="operator_id" required><option value="">Selecciona un operador de prueba</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected(old('operator_id') == $operator->id)>{{ $operator->name }}</option>@endforeach</select></label>
    <label class="section">Fecha del corte<input type="date" name="cut_on" value="{{ old('cut_on', now('America/Merida')->toDateString()) }}" max="{{ now('America/Merida')->toDateString() }}" required></label>
    <p class="footnote">Si ya existe un corte para ese operador y fecha, podrás continuar en ese mismo corte.</p>
    @if($operators->isEmpty())<div class="notice subtle">No tienes operadores del catálogo de prueba asignados.</div>@endif
    <div @class(['dialog-actions' => $modal ?? false, 'row section' => !($modal ?? false)])>
        @if($modal ?? false)<button type="button" class="btn" data-dialog-close>Cancelar</button>@endif
        <button class="btn primary" type="submit" @disabled($operators->isEmpty())>{{ ($modal ?? false) ? 'Guardar corte' : 'Abrir corte' }}</button>
    </div>
</form>
