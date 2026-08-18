@if ($paginator->hasPages())
    <div class="no-print flex items-center justify-end gap-2 text-xs font-semibold text-slate-500">
        <span class="rounded bg-slate-100 px-3 py-2">
            {{ number_format($paginator->firstItem() ?? 0) }}-{{ number_format($paginator->lastItem() ?? 0) }} de {{ number_format($paginator->total()) }}
        </span>
        @if ($paginator->onFirstPage())
            <span class="inline-grid size-8 place-items-center rounded bg-slate-100 text-slate-300" aria-disabled="true">&lsaquo;</span>
        @else
            <a class="inline-grid size-8 place-items-center rounded bg-slate-100 text-slate-600 hover:bg-slate-200" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Pagina anterior">&lsaquo;</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="inline-grid size-8 place-items-center rounded bg-slate-100 text-slate-600 hover:bg-slate-200" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Pagina siguiente">&rsaquo;</a>
        @else
            <span class="inline-grid size-8 place-items-center rounded bg-slate-100 text-slate-300" aria-disabled="true">&rsaquo;</span>
        @endif
    </div>
@endif
