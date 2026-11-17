<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashLoanConversionService;
use App\Domain\Cajas\CashPendingDisbursementService;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashPendingDisbursementController extends Controller
{
    public function create(Request $request, string $cut, string $credit, CashPendingDisbursementService $service): View|RedirectResponse
    {
        [$cut, $credit] = $this->records($request, $cut, $credit, $service);
        $state = $service->readiness($cut, $credit);
        if (! $state['ready']) {
            return redirect()->route('cajas.credits.show', [$cut, $credit->public_id])->withErrors(['disbursement' => $state['reason']]);
        }
        abort_unless($request->user()->can('cajas.accounts.view') && $request->user()->can('cajas.expenses.record'), 403);
        $accounts = CashAccount::visibleTo($request->user())->where('active', true)->where('is_test', true)
            ->where('loans_enabled', true)->where('currency', 'MXN')
            ->whereHas('users', fn ($q) => $q->whereKey($request->user()->id)->where('cash_account_user.can_expense', true))
            ->orderBy('name')->get();

        return view('cajas.credits.disburse', ['cut' => $cut, 'credit' => $credit, 'accounts' => $accounts,
            'sourceHash' => app(CashLoanConversionService::class)->fingerprint($cut, $credit)]);
    }

    public function store(Request $request, string $cut, string $credit, CashPendingDisbursementService $service): RedirectResponse
    {
        [$cut, $credit] = $this->records($request, $cut, $credit, $service);
        try {
            $service->deliver($request->user(), $cut, $credit, $request->all());
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('cajas.credits.show', [$cut, $credit->public_id])->withErrors(['disbursement' => $exception->getMessage()]);
        }

        return redirect()->route('cajas.credits.show', [$cut, $credit->public_id])
            ->with('status', 'Capital entregado en la prueba. Se descontó una sola vez de la caja; convertir el crédito no repetirá la salida.');
    }

    private function records(Request $request, string $cut, string $credit, CashPendingDisbursementService $service): array
    {
        CajasSandbox::enforce();
        $cut = CashCollectionCut::visibleTo($request->user())->where('public_id', $cut)->firstOrFail();
        $service->authorize($request->user(), $cut);

        return [$cut, $cut->pendingLoans()->where('public_id', $credit)->firstOrFail()];
    }
}
