<x-layouts.app title="Créditos pendientes de Cajas">
    <a class="mb-4 inline-block text-sm font-semibold underline" href="{{ route('loans.index') }}">Volver a la cartera</a>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        @forelse ($proposals as $credit)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                <div>
                    <a class="font-bold underline" href="{{ route('loans.cajas-pending.show', $credit->public_id) }}">
                        {{ $credit->proposal['client']['name'] ?? $credit->proposal['client_id'] }}
                    </a>
                    <p class="text-sm">{{ $credit->operator->name }} · {{ $credit->public_id }}</p>
                </div>
                <span class="text-sm font-semibold">
                    {{ ['pending' => 'Pendiente de alta', 'requires_review' => 'Requiere revisión', 'active' => 'Vigente'][$credit->status] }}
                </span>
            </div>
        @empty
            <p class="p-4 text-sm">Todavía no hay propuestas de Cajas.</p>
        @endforelse
        @if ($proposals->hasPages())
            <div class="p-4">{{ $proposals->links() }}</div>
        @endif
    </div>
</x-layouts.app>
