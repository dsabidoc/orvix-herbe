@php
    use App\Support\Money;
    $terms = $credit->proposal['terms'];
    $funding = $credit->funding();
@endphp

<x-layouts.app title="Crédito pendiente de Cajas">
    <a class="mb-4 inline-block text-sm font-semibold underline" href="{{ route('loans.cajas-pending.index') }}">Volver a créditos pendientes</a>

    <section class="space-y-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-bold">{{ $credit->proposal['client']['name'] ?? $credit->proposal['client_id'] }}</h2>
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="font-semibold">Operador</dt><dd>{{ $credit->operator->name }}</dd></div>
            <div><dt class="font-semibold">Corte</dt><dd>{{ $credit->cut_id }} · v{{ $credit->cut_version }}</dd></div>
            <div>
                <dt class="font-semibold">Fondeo</dt>
                <dd>{{ match ($funding['type']) {
                    'offset' => 'Compensación '.$funding['reference_id'],
                    'disbursement' => 'Desembolso '.$funding['reference_id'],
                    default => 'Pendiente de confirmación en Cajas',
                } }}</dd>
            </div>
            <div><dt class="font-semibold">Capital</dt><dd>{{ Money::mxn(Money::decimal($terms['principal_cents'])) }}</dd></div>
            <div><dt class="font-semibold">Tasa mensual</dt><dd>{{ $terms['monthly_rate_percent'] }} %</dd></div>
            <div><dt class="font-semibold">Plazo</dt><dd>{{ $terms['term_months'] }} meses</dd></div>
        </dl>
        @if ($credit->status === 'active')
            <a class="font-bold underline" href="{{ route('loans.show', $credit->loan) }}">Abrir crédito {{ $credit->loan->folio }}</a>
        @else
            @include('loans.partials.cajas-schedule', [
                'heading' => $credit->reviewed_schedule ? 'Calendario confirmado' : 'Calendario propuesto',
                'schedule' => $credit->reviewed_schedule ?? $credit->proposal['proposed_schedule'],
            ])

            @if ($funding['status'] === 'under_reconciliation')
                <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">Alta bloqueada mientras Cajas concilia el fondeo.</p>
            @elseif ($funding['status'] !== 'confirmed')
                <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">El alta requiere un fondeo confirmado por Cajas.</p>
            @elseif ($credit->status === 'requires_review')
                <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    El calendario propuesto difiere del calculado. Confirma el calendario de Préstamos si coincide con las condiciones acordadas.
                </p>
                @include('loans.partials.cajas-schedule', ['heading' => 'Calendario calculado en Préstamos', 'schedule' => $calculated])
                <form method="POST" action="{{ route('loans.cajas-pending.review', $credit->public_id) }}">
                    @csrf
                    <input type="hidden" name="schedule_token" value="{{ $scheduleHash }}">
                    <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white">Confirmar calendario corregido</button>
                </form>
            @else
                <form method="POST" action="{{ route('loans.cajas-pending.store', $credit->public_id) }}">
                    @csrf
                    <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-bold text-white">{{ $funding['type'] === 'offset' ? 'Autorizar alta con esta compensación' : 'Autorizar alta con este desembolso' }}</button>
                </form>
            @endif
        @endif
    </section>
</x-layouts.app>
