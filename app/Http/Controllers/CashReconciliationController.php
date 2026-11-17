<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CashReconciliationService;
use App\Models\CashAccount;
use App\Models\CashReconciliation;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashReconciliationController extends Controller
{
    private function account(Request $request, string $id, CashReconciliationService $service, string $action = 'view'): CashAccount
    {
        $account = CashAccount::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $account, $action);

        return $account;
    }

    public function create(Request $request, string $account, CashReconciliationService $service)
    {
        $account = $this->account($request, $account, $service, 'count');
        $pending = CashReconciliation::where('active_account_id', $account->id)->first();
        if ($request->boolean('dialog') && $pending) {
            return view('cajas.partials.reconciliation-dialog', ['account' => $account, 'pending' => $pending]);
        }
        if ($pending) {
            return redirect()->route('cajas.reconciliations.show', [$account, $pending]);
        }
        $snapshot = $service->snapshot($account);

        return view($request->boolean('dialog') ? 'cajas.partials.reconciliation-dialog' : 'cajas.reconciliations.form', ['account' => $account, 'snapshot' => $snapshot, 'sourceToken' => $service->token($snapshot)]);
    }

    public function store(Request $request, string $account, CashReconciliationService $service)
    {
        $account = $this->account($request, $account, $service, 'count');
        try {
            $record = $service->record($request->user(), $account, $request->all());
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409 || $request->expectsJson()) {
                throw $e;
            }

            return back()->withInput()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        $message = 'Revisión de saldo enviada a Herbe.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'detail' => route('cajas.reconciliations.show', [$account, $record])]);
        }

        return redirect()->route('cajas.reconciliations.show', [$account, $record])->with('status', $message);
    }

    public function show(Request $request, string $account, string $reconciliation, CashReconciliationService $service)
    {
        $account = $this->account($request, $account, $service);
        $record = CashReconciliation::where('cash_account_id', $account->id)->where('public_id', $reconciliation)->with(['creator', 'resolver', 'previousClose'])->firstOrFail();
        $stale = $record->status === 'pending' && ! hash_equals($record->source_token, $service->token($service->snapshot($account)));

        return view('cajas.reconciliations.show', ['account' => $account, 'record' => $record, 'snapshot' => $record->snapshot, 'stale' => $stale]);
    }

    public function resolve(Request $request, string $account, string $reconciliation, string $action, CashReconciliationService $service)
    {
        $account = $this->account($request, $account, $service);
        $record = CashReconciliation::where('cash_account_id', $account->id)->where('public_id', $reconciliation)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return redirect()->route('cajas.reconciliations.show', [$account, $record])->with('status', 'Resolución guardada en el historial. No se movió dinero.');
    }
}
