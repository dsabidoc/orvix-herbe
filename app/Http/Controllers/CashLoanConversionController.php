<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashLoanConversionService;
use App\Domain\Cajas\CashPendingDisbursementService;
use App\Models\CashCollectionCut;
use App\Models\CashPendingLoan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashLoanConversionController extends Controller
{
    public function index(Request $request): View
    {
        CajasSandbox::enforce();
        abort_unless($request->user()->can('cajas.collections.loans.view') && $request->user()->can('cajas.pending-loans.view'), 403);
        $converted = $request->query('status') === 'converted';
        $cancelled = $request->query('status') === 'cancelled';
        $credits = CashPendingLoan::whereHas('cut', fn ($q) => $q->visibleTo($request->user()))
            ->where('status', '!=', 'cancelled')
            ->when($cancelled, fn ($q) => $q->where(fn ($q) => $q->whereHas('disbursement.corrections', fn ($r) => $r->where('status', 'approved')->where('kind', 'cancel'))
                ->orWhereHas('offset.corrections', fn ($r) => $r->where('status', 'approved')->where('kind', 'cancel'))),
                fn ($q) => $q->whereDoesntHave('disbursement.corrections', fn ($r) => $r->where('status', 'approved')->where('kind', 'cancel'))
                    ->whereDoesntHave('offset.corrections', fn ($r) => $r->where('status', 'approved')->where('kind', 'cancel'))
                    ->when($converted, fn ($r) => $r->whereHas('conversion.loan', fn ($l) => $l->where('active', true)), fn ($r) => $r->doesntHave('conversion')))
            ->with(['cut.operator', 'conversion.loan'])->latest('id')->paginate(15)->withQueryString();

        return view('cajas.credits.index', compact('credits', 'converted', 'cancelled'));
    }

    public function show(Request $request, string $cut, string $credit, CashLoanConversionService $service): View
    {
        [$cut, $credit] = $this->records($request, $cut, $credit, $service);

        return view('cajas.credits.show', ['cut' => $cut, 'credit' => $credit,
            'conversion' => $credit->conversion()->with(['loan', 'creator'])->first(),
            'offset' => $credit->offset()->with('creator')->first(),
            'disbursement' => $credit->disbursement()->with(['account', 'creator', 'operation'])->first(),
            'deliveryState' => app(CashPendingDisbursementService::class)->readiness($cut, $credit),
            'readiness' => $service->readiness($cut, $credit), 'sourceHash' => $service->fingerprint($cut, $credit)]);
    }

    public function store(Request $request, string $cut, string $credit, CashLoanConversionService $service): RedirectResponse
    {
        [$cut, $credit] = $this->records($request, $cut, $credit, $service);
        try {
            $service->convert($request->user(), $cut, $credit, $request->all());
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return back()->withErrors(['conversion' => $exception->getMessage()]);
        }

        return redirect()->route('cajas.credits.show', [$cut, $credit->public_id])
            ->with('status', 'Préstamo vigente de prueba. Se conservó la entrega de capital original; la conversión no movió dinero.');
    }

    private function records(Request $request, string $cut, string $credit, CashLoanConversionService $service): array
    {
        CajasSandbox::enforce();
        $cut = CashCollectionCut::visibleTo($request->user())->where('public_id', $cut)->firstOrFail();
        $service->authorize($request->user(), $cut);

        return [$cut, $cut->pendingLoans()->where('public_id', $credit)->firstOrFail()];
    }
}
