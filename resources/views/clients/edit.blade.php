<x-layouts.app title="Editar cliente">
    <div class="mx-auto max-w-3xl rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-bold text-slate-950">Editar cliente</h2>
            <p class="mt-1 text-sm text-slate-500">Estos datos se muestran en los prestamos y en cartera automaticamente.</p>
        </div>

        <form class="p-5" method="POST" action="{{ route('clients.update', $client) }}">
            @csrf
            @method('PUT')

            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-1">
                    <span class="text-sm font-semibold text-slate-700">Nombre</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="first_name" required value="{{ old('first_name', $client->first_name) }}">
                    @error('first_name')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1">
                    <span class="text-sm font-semibold text-slate-700">Apellidos</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="last_name" required value="{{ old('last_name', $client->last_name) }}">
                    @error('last_name')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1">
                    <span class="text-sm font-semibold text-slate-700">Celular</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="phone" required value="{{ old('phone', $client->phone) }}">
                    @error('phone')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1">
                    <span class="text-sm font-semibold text-slate-700">Celular alterno</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="alternate_phone" value="{{ old('alternate_phone', $client->alternate_phone) }}">
                    @error('alternate_phone')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1 md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">Correo</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="email" type="email" value="{{ old('email', $client->email) }}">
                    @error('email')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1 md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">Notas</span>
                    <textarea class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="notes" rows="4">{{ old('notes', $client->notes) }}</textarea>
                    @error('notes')<span class="text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                </label>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <a class="rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700" href="{{ route('clients.show', $client) }}">Cancelar</a>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white hover:bg-[#0f766e]" type="submit">Guardar cambios</button>
            </div>
        </form>
    </div>
</x-layouts.app>
