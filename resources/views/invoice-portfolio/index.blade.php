@php
    use Carbon\CarbonImmutable;

    $holderLabel = function (?string $holder): string {
        if (blank($holder)) {
            return 'Sin ubicacion';
        }

        $normalized = str($holder)->lower()->ascii()->toString();

        return match (true) {
            str_contains($normalized, 'caja') => 'Caja',
            str_contains($normalized, 'recepcion') => 'Recepcion',
            str_contains($normalized, 'operador') => 'Operador',
            default => $holder,
        };
    };

    $vehicleTitle = fn ($loan) => trim(($loan->vehicle?->model ?: 'Vehiculo').' · Dia '.$loan->payment_day);
    $clientName = fn ($loan) => trim(($loan->client?->first_name ?? '').' '.($loan->client?->last_name ?? '')) ?: 'Sin cliente';
    $selectedOperator = (($filters['operator_id'] ?? '') === 'none')
        ? 'Sin operador asignado'
        : $operators->firstWhere('id', (int) ($filters['operator_id'] ?? 0))?->name;
@endphp

<x-layouts.app title="Facturas">
    <div class="no-print mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Control de facturas</p>
            <p class="mt-1 text-sm text-slate-600">Ubicacion fisica de facturas por prestamo activo.</p>
        </div>
        <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white shadow-sm" type="button" onclick="window.print()">Exportar PDF</button>
    </div>

    <form class="no-print mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm" method="GET" action="{{ route('invoice-portfolio.index') }}">
        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_auto_auto] lg:items-end">
            <div>
                <label class="text-sm font-semibold text-slate-700" for="operator_id">Operador</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="operator_id" name="operator_id">
                    <option value="">Todos</option>
                    @unless(auth()->user()->hasRole('operador-cartera'))
                        <option value="none" @selected(($filters['operator_id'] ?? '') === 'none')>Sin operador asignado</option>
                    @endunless
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->id }}" @selected((string) ($filters['operator_id'] ?? '') === (string) $operator->id)>{{ $operator->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700" for="holder">Ubicacion de factura</label>
                <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="holder" name="holder">
                    @foreach ($holderOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['holder'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="rounded-md bg-[#0d9488] px-5 py-2 text-sm font-bold text-white" type="submit">Filtrar</button>
            <a class="rounded-md border border-slate-300 bg-white px-5 py-2 text-center text-sm font-bold text-slate-700" href="{{ route('invoice-portfolio.index') }}">Todas</a>
        </div>
    </form>

    <section class="no-print overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-bold text-slate-950">Listado de facturas</h3>
            <p class="mt-1 text-sm text-slate-500">Filtra por Caja, Recepcion u Operador para imprimir la relacion correspondiente.</p>
        </div>

        <div class="hidden overflow-x-auto lg:block">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Modelo / dia de pago</th>
                        <th class="px-5 py-3">Folio</th>
                        <th class="px-5 py-3">Cliente</th>
                        <th class="px-5 py-3">Donde esta la factura</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $loan)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-4">
                                <a class="font-semibold text-slate-950" href="{{ route('loans.show', $loan) }}">{{ $vehicleTitle($loan) }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ $loan->vehicle?->brand ?: 'Sin marca' }} {{ $loan->vehicle?->year ?: '' }}</p>
                            </td>
                            <td class="px-5 py-4 font-semibold text-[#0f766e]">{{ $loan->folio }}</td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-950">{{ $clientName($loan) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $loan->operator?->name ?? 'Sin operador' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded px-2 py-1 text-xs font-bold {{ blank($loan->invoice_holder) ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700' }}">{{ $holderLabel($loan->invoice_holder) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-5 py-8 text-center text-slate-500" colspan="4">No hay facturas para mostrar con ese filtro.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-slate-100 lg:hidden">
            @forelse ($rows as $loan)
                <a class="block p-4" href="{{ route('loans.show', $loan) }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-950">{{ $vehicleTitle($loan) }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $loan->folio }}</p>
                            <p class="mt-2 text-sm font-semibold text-[#0f766e]">{{ $clientName($loan) }}</p>
                        </div>
                        <span class="shrink-0 rounded px-2 py-1 text-xs font-bold {{ blank($loan->invoice_holder) ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700' }}">{{ $holderLabel($loan->invoice_holder) }}</span>
                    </div>
                </a>
            @empty
                <p class="p-5 text-center text-sm text-slate-500">No hay facturas para mostrar con ese filtro.</p>
            @endforelse
        </div>

        <div class="border-t border-slate-200 px-5 py-4">
            {{ $rows->links() }}
        </div>
    </section>

    <section class="print-sheet hidden">
        <div class="mb-3">
            <p class="text-[10px] text-slate-500">Fecha de exportacion: {{ CarbonImmutable::now('America/Merida')->format('d/m/Y H:i') }}</p>
            <h3 class="mt-1 text-base font-bold text-slate-950">Listado de facturas</h3>
            <p class="text-xs text-slate-600">Operador: {{ $selectedOperator ?: 'Todos' }} · Ubicacion: {{ $holderOptions[$filters['holder'] ?? ''] ?? 'Todas las ubicaciones' }}</p>
        </div>

        <table class="cut-print-table w-full text-left text-sm">
            <thead>
                <tr>
                    <th>Modelo / dia de pago</th>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>Donde esta la factura</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($printRows as $loan)
                    <tr>
                        <td>{{ $vehicleTitle($loan) }}</td>
                        <td>{{ $loan->folio }}</td>
                        <td>{{ $clientName($loan) }}</td>
                        <td>{{ $holderLabel($loan->invoice_holder) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No hay facturas para mostrar con ese filtro.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
