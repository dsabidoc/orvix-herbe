@php
    $kpiStyles = [
        'blue' => 'border-blue-200 bg-blue-50/80 text-blue-700',
        'orange' => 'border-orange-200 bg-orange-50/80 text-orange-700',
        'yellow' => 'border-yellow-200 bg-yellow-50/80 text-yellow-700',
        'green' => 'border-emerald-200 bg-emerald-50/80 text-emerald-700',
        'red' => 'border-red-200 bg-red-50/80 text-red-700',
        'slate' => 'border-slate-200 bg-slate-50 text-slate-700',
    ];
    $kpiDots = [
        'blue' => '#2563eb',
        'orange' => '#f97316',
        'yellow' => '#eab308',
        'green' => '#10b981',
        'red' => '#ef4444',
        'slate' => '#64748b',
    ];
@endphp

<div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
    @foreach ($kpis as $kpi)
        @php
            $color = $kpi['color'] ?? 'slate';
        @endphp
        <article class="{{ $kpiStyles[$color] ?? $kpiStyles['slate'] }} rounded-lg border p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-bold">{{ $kpi['title'] }}</p>
                <span class="size-2.5 rounded-full" style="background-color: {{ $kpiDots[$color] ?? $kpiDots['slate'] }}"></span>
            </div>
            <p class="mt-3 break-words text-[clamp(1.35rem,1.7vw,1.5rem)] font-bold leading-tight text-slate-950">{{ $kpi['value'] }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $kpi['caption'] }}</p>
        </article>
    @endforeach
</div>
