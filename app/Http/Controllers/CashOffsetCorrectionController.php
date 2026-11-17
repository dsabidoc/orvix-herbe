<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashOffsetCorrectionService;
use App\Models\CashCollectionCut;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashOffsetCorrectionController extends Controller
{
    public function create(Request $request, string $cut, string $credit, string $kind, CashOffsetCorrectionService $service)
    {
        [$cut, $credit, $offset] = $this->records($request, $cut, $credit, $service);
        abort_unless(in_array($kind, ['correct', 'cancel']) && ! $cut->hasPendingCorrection() && ! $offset->effectiveState()['cancelled'], 409, 'Resuelve la solicitud pendiente o consulta el historial de la cancelación.');

        return view('cajas.offset-corrections.form', compact('cut', 'credit', 'offset', 'kind'));
    }

    public function preview(Request $request, string $cut, string $credit, string $kind, CashOffsetCorrectionService $service)
    {
        [$cut, $credit, $offset] = $this->records($request, $cut, $credit, $service);
        $data = $request->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:500']]);
        abort_unless($cut->version === (int) $data['version'] && ! $cut->hasPendingCorrection(), 409, 'El corte cambió. Actualiza antes de proponer.');
        $changes = $request->only(['executed_on', 'reference', 'cancel_type', 'effective_on', 'funds_confirmed', 'amount', 'amount_cents', 'cash_pending_loan_id']);
        try {
            $proposal = $service->quote($request->user(), $offset, $kind, $changes);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['correction' => $exception->getMessage()]);
        }
        $token = Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'offset_id' => $offset->id, 'expires_at' => now()->addMinutes(30)->timestamp,
            'kind' => $kind, 'data' => [...$data, 'changes' => $changes, 'idempotency_key' => (string) Str::uuid(), 'loan_hash' => hash('sha256', json_encode($proposal))]]));

        return view('cajas.offset-corrections.preview', compact('cut', 'credit', 'offset', 'kind', 'proposal', 'token', 'data'));
    }

    public function store(Request $request, string $cut, string $credit, CashOffsetCorrectionService $service)
    {
        [$cut, $credit, $offset] = $this->records($request, $cut, $credit, $service);
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted']]);
        try {
            $token = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Vista previa inválida.');
        }
        abort_unless($token['user_id'] === $request->user()->id && $token['offset_id'] === $offset->id, 403);
        abort_if($token['expires_at'] < now()->timestamp, 409, 'La vista previa venció. Revísala nuevamente.');
        $record = $service->request($request->user(), $offset, $token['kind'], [...$token['data'], 'confirmed' => 1]);

        return redirect()->route('cajas.offset-corrections.show', [$cut, $credit->public_id, $record])->with('status', 'Solicitud enviada a Herbe. La compensación y el dinero siguen sin cambios.');
    }

    public function show(Request $request, string $cut, string $credit, string $correction, CashOffsetCorrectionService $service)
    {
        [$cut, $credit, $offset] = $this->records($request, $cut, $credit, $service, $request->user()->can('cajas.offsets.correct-resolve'));
        $record = $offset->corrections()->where('public_id', $correction)->with(['creator', 'resolver'])->firstOrFail();

        return view('cajas.offset-corrections.show', ['cut' => $cut, 'credit' => $credit, 'offset' => $offset, 'record' => $record, 'proposal' => $record->proposal, 'kind' => $record->kind]);
    }

    public function resolve(Request $request, string $cut, string $credit, string $correction, string $action, CashOffsetCorrectionService $service)
    {
        [$cut, $credit, $offset] = $this->records($request, $cut, $credit, $service, true);
        $record = $offset->corrections()->where('public_id', $correction)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return back()->withErrors(['correction' => $exception->getMessage()]);
        }

        return redirect()->route('cajas.offset-corrections.show', [$cut, $credit->public_id, $record])->with('status', $action === 'approve' ? 'Resolución aplicada en la prueba. El original y el historial se conservaron.' : 'Solicitud rechazada sin mover dinero.');
    }

    private function records(Request $request, string $id, string $credit, CashOffsetCorrectionService $service, bool $resolve = false): array
    {
        CajasSandbox::enforce();
        $cut = CashCollectionCut::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $cut, $resolve);
        $credit = $cut->pendingLoans()->where('public_id', $credit)->firstOrFail();

        return [$cut, $credit, $credit->offset()->firstOrFail()];
    }
}
