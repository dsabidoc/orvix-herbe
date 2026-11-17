<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashMovementService;
use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashMovementController extends Controller
{
    public function create(Request $request, string $account, string $kind, CashMovementService $service): View
    {
        CajasSandbox::enforce();
        abort_unless(in_array($kind, ['income', 'expense', 'transfer']), 404);
        $account = CashAccount::visibleTo($request->user())->where('public_id', $account)->firstOrFail();
        $service->authorize($request->user(), $account, $kind === 'transfer' ? 'send' : $kind);

        return view($request->boolean('dialog') ? 'cajas.partials.movement-dialog' : 'cajas.movement-form', [
            'account' => $account, 'kind' => $kind, 'token' => (string) Str::uuid(),
            'destinations' => $kind === 'transfer' ? $account->destinations()->where('active', true)->where('is_test', true)
                ->where('currency', $account->currency)->orderBy('name')->get(['cash_accounts.public_id', 'cash_accounts.name']) : collect(),
        ]);
    }

    public function store(Request $request, string $account, string $kind, CashMovementService $service): RedirectResponse|JsonResponse
    {
        CajasSandbox::enforce();
        $account = CashAccount::visibleTo($request->user())->where('public_id', $account)->firstOrFail();
        $operation = $service->create($request->user(), $account, $kind, $request->all());

        $message = $kind === 'transfer'
            ? 'Traspaso preparado. El saldo cambiará al confirmar la entrega.' : 'Movimiento de prueba registrado.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'detail' => route('cajas.operations.show', $operation)]);
        }

        return redirect()->route('cajas.operations.show', $operation)->with('status', $message);
    }

    public function show(Request $request, string $operation): View
    {
        abort_unless($request->user()->can('cajas.accounts.view'), 403);
        $record = CashOperation::visibleTo($request->user())->where('public_id', $operation)->with(['source', 'destination'])->firstOrFail();

        return view('cajas.operation', [
            'operation' => $record,
            'events' => AuditEvent::where('auditable_type', CashOperation::class)->where('auditable_id', $record->id)->with('user:id,name')->orderBy('id')->get(),
        ]);
    }

    public function transitionForm(Request $request, string $operation, string $action, CashMovementService $service): View
    {
        CajasSandbox::enforce();
        $record = CashOperation::visibleTo($request->user())->where('public_id', $operation)
            ->where('kind', 'transfer')->with(['source', 'destination'])->firstOrFail();
        $delivering = $action === 'deliver';
        $account = $delivering ? $record->source : $record->destination;
        $service->authorize($request->user(), $account, $delivering ? 'send' : 'receive');
        abort_unless(in_array($record->status, $delivering ? ['prepared'] : ['delivered', 'disputed'], true), 409, 'El estado del traspaso cambió. Actualiza la caja.');

        return view($request->boolean('dialog') ? 'cajas.partials.transfer-dialog' : 'cajas.transfer-form', [
            'operation' => $record, 'account' => $account, 'action' => $action,
            'title' => $delivering ? 'Confirmar entrega' : 'Revisar recepción',
            'button' => $delivering ? 'Confirmar entrega' : 'Aceptar importe completo',
        ]);
    }

    public function transition(Request $request, string $operation, string $action, CashMovementService $service): RedirectResponse|JsonResponse
    {
        $record = $service->transition($request->user(), $operation, $action, $request->all());

        $message = 'Traspaso: '.$record->statusLabel().'.';
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message, 'detail' => route('cajas.operations.show', $record), 'detailLabel' => 'Ver comprobante →',
            ]);
        }

        return redirect()->route('cajas.operations.show', $record)->with('status', $message);
    }
}
