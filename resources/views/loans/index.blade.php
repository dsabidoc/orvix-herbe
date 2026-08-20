@php
    use App\Support\Money;
@endphp

<x-layouts.app title="Cartera">
    @can('loans.formalize')
        <div class="mb-4 flex justify-end">
            <a class="rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white" href="{{ route('loans.create') }}">Crear prestamo</a>
        </div>
    @endcan

    <form class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[minmax(0,1fr)_180px_180px_auto] md:items-end" method="GET">
        <div class="flex-1">
            <label class="text-sm font-semibold text-slate-700" for="q">Buscar cliente, folio, vehiculo, placas o VIN</label>
            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="q" name="q" value="{{ request('q') }}">
        </div>
        <div>
            <label class="text-sm font-semibold text-slate-700" for="bucket">Vista</label>
            <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="bucket" name="bucket">
                <option value="">Todos</option>
                <option value="today" @selected(request('bucket') === 'today')>Vence hoy</option>
                <option value="overdue" @selected(request('bucket') === 'overdue')>Vencidos</option>
            </select>
        </div>
        <div>
            <label class="text-sm font-semibold text-slate-700" for="collection_status">Estado</label>
            <select class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="collection_status" name="collection_status">
                <option value="">Todos</option>
                <option value="active" @selected(request('collection_status') === 'active')>Activos</option>
                <option value="frozen" @selected(request('collection_status') === 'frozen')>Congelados</option>
            </select>
        </div>
        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" type="submit">Filtrar</button>
    </form>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            @include('partials.table-pagination', ['paginator' => $loans])
        </div>
        <table class="hidden w-full text-left text-sm lg:table">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-5 py-3">Prestamo</th>
                    <th class="px-5 py-3">Vehiculo</th>
                    <th class="px-5 py-3">Operador</th>
                    <th class="px-5 py-3">Estado</th>
                    <th class="px-5 py-3 text-right">Saldo</th>
                    <th class="px-5 py-3">Contacto</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($loans as $loan)
                    @php
                        $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
                        $overdue = $loan->installments->filter(fn ($installment) => Money::cents($installment->remaining_amount) > 0 && $installment->due_date->toDateString() < $today)->count();
                        $balance = $loan->installments->sum(fn ($installment) => Money::cents($installment->remaining_amount));
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3">
                            <a class="font-semibold text-[#0f766e]" href="{{ route('loans.show', $loan) }}">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</a>
                            <p class="text-xs text-slate-500">{{ $loan->folio }} · proxima {{ $next?->due_date?->format('d/m/Y') ?? 'liquidado' }}</p>
                        </td>
                        <td class="px-5 py-3">{{ $loan->vehicle?->model }} {{ $loan->vehicle?->year }} · {{ $loan->vehicle?->color }}</td>
                        <td class="px-5 py-3">{{ $loan->operator?->name }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded px-2 py-1 text-xs font-bold {{ $overdue >= 3 ? 'bg-red-50 text-red-700' : ($overdue > 0 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') }}">
                                {{ $overdue > 0 ? $overdue.' vencida(s)' : 'al corriente' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right font-semibold">{{ Money::mxn(Money::decimal($balance)) }}</td>
                        <td class="px-5 py-3">
                            <a class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold" href="https://wa.me/52{{ preg_replace('/\D+/', '', $loan->client->phone) }}" target="_blank" rel="noreferrer">WhatsApp</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divide-y divide-slate-100 lg:hidden">
            @foreach ($loans as $loan)
                @php
                    $next = $loan->installments->first(fn ($installment) => Money::cents($installment->remaining_amount) > 0);
                    $balance = $loan->installments->sum(fn ($installment) => Money::cents($installment->remaining_amount));
                @endphp
                <a class="block p-4" href="{{ route('loans.show', $loan) }}">
                    <p class="font-semibold text-[#0f766e]">{{ $loan->client->first_name }} {{ $loan->client->last_name }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $loan->folio }} · {{ $loan->vehicle?->model }} · {{ $loan->operator?->name }}</p>
                    <p class="mt-2 text-sm font-semibold">{{ Money::mxn(Money::decimal($balance)) }} · {{ $next?->due_date?->format('d/m/Y') ?? 'liquidado' }}</p>
                </a>
            @endforeach
        </div>
    </div>

    <div class="mt-4">{{ $loans->links() }}</div>
</x-layouts.app>
