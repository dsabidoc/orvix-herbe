@php
    use App\Support\StatusLabels;
@endphp

<x-layouts.app title="Expedientes">
    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" method="GET" action="{{ route('documents.index') }}">
        <div class="grid gap-3 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="q">Buscar archivo, cliente, prestamo, vehiculo, placas o VIN</label>
                <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" type="search" value="{{ request('q') }}" placeholder="Factura, Natalia, ORV-26-0005, Aveo...">
            </div>
            <button class="rounded-md bg-[#0d9488] px-5 py-2 text-sm font-bold text-white" type="submit">Buscar</button>
        </div>
    </form>

    <section class="mt-4 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Archivos de expedientes</h3>
            <p class="mt-1 text-sm text-slate-500">Documentos cargados en los prestamos visibles para tu usuario.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Archivo</th>
                        <th class="px-5 py-3">Cliente</th>
                        <th class="px-5 py-3">Prestamo</th>
                        <th class="px-5 py-3">Operador</th>
                        <th class="px-5 py-3">Nota</th>
                        <th class="px-5 py-3 text-right">Tamano</th>
                        <th class="px-5 py-3">Fecha</th>
                        <th class="px-5 py-3 text-right">Accion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($documents as $document)
                        <tr>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-950">{{ $document->original_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ StatusLabels::document($document->status) }}</p>
                            </td>
                            <td class="px-5 py-4">
                                @if ($document->client)
                                    <a class="font-semibold text-[#0f766e]" href="{{ route('clients.show', $document->client) }}">{{ $document->client->first_name }} {{ $document->client->last_name }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $document->client->phone }}</p>
                                @else
                                    <span class="text-slate-400">Sin cliente</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($document->loan)
                                    <a class="font-semibold text-slate-950" href="{{ route('loans.show', $document->loan) }}">{{ $document->loan->folio }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $document->loan->vehicle?->model }} {{ $document->loan->vehicle?->year }}</p>
                                @else
                                    <span class="text-slate-400">Sin prestamo</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">{{ $document->loan?->operator?->name ?? 'Sin operador' }}</td>
                            <td class="max-w-xs px-5 py-4 text-slate-600">{{ $document->notes ?: '-' }}</td>
                            <td class="px-5 py-4 text-right font-semibold">{{ number_format($document->size / 1024, 1) }} KB</td>
                            <td class="px-5 py-4 text-slate-600">{{ $document->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-1">
                                    <a class="grid size-8 place-items-center rounded-md border border-slate-200 bg-white text-slate-600 hover:text-[#0f766e]" href="{{ route('documents.download', $document) }}" title="Descargar archivo">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                                    </a>
                                    <form method="POST" action="{{ route('documents.destroy', $document) }}" data-confirm-delete data-confirm-title="¿Eliminar este archivo del expediente?" data-confirm-message="Esta accion quitara el archivo del expediente. Si fue un error, tendran que volver a subirlo.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="grid size-8 place-items-center rounded-md border border-red-100 bg-white text-red-600 hover:bg-red-50" type="submit" title="Eliminar archivo">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-8 text-slate-500" colspan="8">No hay archivos para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-5 py-4">
            {{ $documents->links() }}
        </div>
    </section>
</x-layouts.app>
