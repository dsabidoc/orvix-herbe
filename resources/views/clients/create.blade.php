<x-layouts.app title="Agregar cliente">
    <form class="max-w-3xl rounded-lg border border-slate-200 bg-white p-5 shadow-sm" method="POST" action="{{ route('clients.store') }}">
        @csrf
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="text-sm font-semibold text-slate-700">Nombre</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" required>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Apellidos</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" required>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Celular</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" required>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Correo</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" type="email">
            </div>
            @unless (auth()->user()->hasRole('operador-cartera'))
                <div>
                    <label class="text-sm font-semibold text-slate-700">Operador</label>
                    <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="operator_id">
                        <option value="">Sin asignar</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div class="md:col-span-2">
                <label class="text-sm font-semibold text-slate-700">Notas</label>
                <textarea class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="3"></textarea>
            </div>
        </div>
        <button class="mt-4 rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar cliente</button>
    </form>
</x-layouts.app>
