<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashCutCorrectionService;
use App\Domain\Cajas\CashCutWorkflow;
use App\Models\CashCollectionCut;
use App\Models\CashTestLoan;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashCutCorrectionController extends Controller
{
    public function create(Request $request, string $cut, string $kind, int $target, CashCutCorrectionService $service): View
    {
        $cut = $this->cut($request, $cut, $service, 'cajas.corrections.request');
        abort_unless($cut->custody_locked_at && ! $cut->hasPendingCorrection(), 409, 'El corte debe tener una entrega y no tener otra corrección pendiente.');
        abort_unless(in_array($kind, ['receipt', 'item']), 404);
        $record = $kind === 'receipt' ? $cut->receipts()->with('account')->whereKey($target)->firstOrFail()
            : ($target ? $cut->items()->whereKey($target)->firstOrFail() : null);
        if ($kind === 'receipt') {
            app(CashCutWorkflow::class)->receiptAccount($request->user(), $record->account->public_id);
        }

        return view('cajas.corrections.form', ['cut' => $cut, 'kind' => $kind, 'target' => $target, 'record' => $record,
            'loans' => $kind === 'item' ? CashTestLoan::where('operator_id', $cut->operator_id)->where('active', true)->with('installments')->get() : collect()]);
    }

    public function preview(Request $request, string $cut, string $kind, int $target, CashCutCorrectionService $service): View
    {
        $cut = $this->cut($request, $cut, $service, 'cajas.corrections.request');
        $data = $request->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000'], 'reference' => ['required', 'string', 'max:500']]);
        abort_unless($cut->version === (int) $data['version'] && ! $cut->hasPendingCorrection(), 409, 'El corte cambió. Actualiza antes de proponer.');
        $changes = $request->only(['amount', 'installment_id', 'include_interest', 'remove']);
        $proposal = $service->quote($request->user(), $cut, $kind, $target, $changes);
        $request->session()->flashInput($request->except('_token'));
        $token = Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'cut_id' => $cut->id, 'kind' => $kind, 'target' => $target,
            'expires_at' => now()->addMinutes(30)->timestamp, 'data' => [...$data, 'changes' => $changes,
                'idempotency_key' => (string) Str::uuid(), 'proposal_hash' => hash('sha256', json_encode($proposal))]]));

        return view('cajas.corrections.preview', ['cut' => $cut, 'proposal' => $proposal, 'token' => $token, 'reason' => $data['reason'], 'reference' => $data['reference']]);
    }

    public function store(Request $request, string $cut, CashCutCorrectionService $service): RedirectResponse
    {
        $cut = $this->cut($request, $cut, $service, 'cajas.corrections.request');
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted']]);
        try {
            $token = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'La vista previa no es válida.');
        }
        abort_unless($token['user_id'] === $request->user()->id && $token['cut_id'] === $cut->id, 403);
        abort_if($token['expires_at'] < now()->timestamp, 409, 'La vista previa venció; vuelve a revisarla.');
        $record = $service->request($request->user(), $cut, $token['kind'], $token['target'], [...$token['data'], 'confirmed' => 1]);

        return redirect()->route('cajas.corrections.show', [$cut, $record])->with('status', 'Corrección enviada a Herbe. Los importes originales y el dinero no cambiaron.');
    }

    public function show(Request $request, string $cut, string $correction, CashCutCorrectionService $service): View
    {
        $permission = $request->user()->can('cajas.corrections.resolve') ? 'cajas.corrections.resolve' : 'cajas.corrections.request';
        $cut = $this->cut($request, $cut, $service, $permission);
        $record = $cut->corrections()->where('public_id', $correction)->with(['creator', 'resolver'])->firstOrFail();

        return view('cajas.corrections.show', ['cut' => $cut, 'correction' => $record, 'proposal' => $record->proposal]);
    }

    public function resolve(Request $request, string $cut, string $correction, string $action, CashCutCorrectionService $service): RedirectResponse
    {
        $cut = $this->cut($request, $cut, $service, 'cajas.corrections.resolve');
        $record = $cut->corrections()->where('public_id', $correction)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('cajas.corrections.show', [$cut, $record])->withErrors(['correction' => $exception->getMessage()]);
        }

        return redirect()->route('cajas.corrections.show', [$cut, $record])->with('status', $action === 'approve'
            ? 'Corrección aplicada en la prueba. El historial original se conservó.' : 'Corrección rechazada. Los importes y el dinero permanecen sin cambios.');
    }

    private function cut(Request $request, string $id, CashCutCorrectionService $service, string $permission): CashCollectionCut
    {
        CajasSandbox::enforce();
        $cut = CashCollectionCut::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $cut, $permission);

        return $cut;
    }
}
