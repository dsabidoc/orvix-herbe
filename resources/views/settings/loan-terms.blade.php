<x-layouts.app title="Configuracion · Plazos">
    @include('settings._nav')

    <div class="mb-4 flex justify-end">
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="button" data-open-modal="create-loan-term-modal">Agregar plazo</button>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 p-5">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
            <h3 class="mt-1 text-lg font-bold text-slate-950">Plazos de prestamos</h3>
            <p class="mt-1 text-sm text-slate-500">Estos plazos aparecen en el dropdown de prestamos regulares y con redondeo. No aplican para prestamos de solo interes.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Plazo</th>
                        <th class="px-5 py-3">Estado</th>
                        <th class="px-5 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($terms as $term)
                        <tr>
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-950">{{ $term->term_months }} meses</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded px-2 py-1 text-xs font-bold {{ $term->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $term->is_active ? 'Activo' : 'Inactivo' }}</span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                @if ($term->is_active)
                                    <form class="inline" method="POST" action="{{ route('settings.loan-terms.destroy', $term) }}" data-confirm-delete data-confirm-title="¿Quitar este plazo?" data-confirm-message="El plazo dejara de aparecer en el dropdown de prestamos nuevos. Los prestamos existentes no cambian.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-md border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700" type="submit">Quitar</button>
                                    </form>
                                @else
                                    <form class="inline" method="POST" action="{{ route('settings.loan-terms.store') }}">
                                        @csrf
                                        <input name="term_months" type="hidden" value="{{ $term->term_months }}">
                                        <button class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" type="submit">Reactivar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <dialog id="create-loan-term-modal" class="w-[min(92vw,420px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
        <form method="POST" action="{{ route('settings.loan-terms.store') }}">
            @csrf
            <div class="border-b border-slate-200 px-5 py-4">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Configuracion</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Agregar plazo</h3>
            </div>
            <div class="px-5 py-4">
                <label class="text-sm font-semibold text-slate-700">Meses
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" name="term_months" placeholder="Ej. 60" type="number" min="1" max="1200" step="1" required>
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" type="button" data-close-modal>No</button>
                <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Guardar plazo</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
