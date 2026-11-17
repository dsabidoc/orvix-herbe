<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CashManualCorrectionService;
use App\Models\CashOperation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashManualCorrectionController extends Controller
{
    private function operation(Request $request, string $id, CashManualCorrectionService $service, bool $resolve = false): CashOperation
    {
        $operation = CashOperation::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $operation, $resolve ? 'resolve' : 'request');

        return $operation;
    }

    public function create(Request $request, string $operation, string $kind, CashManualCorrectionService $service)
    {
        $operation = $this->operation($request, $operation, $service);
        abort_unless(in_array($kind, ['correct', 'cancel']) && ! $operation->effectiveManualState()['cancelled'] && ! $operation->manualCorrections()->where('status', 'pending')->exists(), 409, 'Consulta el historial o resuelve la solicitud pendiente.');

        return view('cajas.manual-corrections.form', ['operation' => $operation, 'kind' => $kind, 'sourceToken' => $service->fingerprint($operation)]);
    }

    public function preview(Request $request, string $operation, string $kind, CashManualCorrectionService $service)
    {
        $operation = $this->operation($request, $operation, $service);
        $data = $request->validate(['source_token' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:500']]);
        abort_unless(hash_equals($service->fingerprint($operation), $data['source_token']) && ! $operation->manualCorrections()->where('status', 'pending')->exists(), 409, 'Cambió el movimiento manual. Actualiza antes de proponer.');
        $changes = $request->only(['amount', 'operated_on', 'reference', 'cancel_type', 'effective_on', 'funds_confirmed', 'source_id', 'destination_id', 'account_id', 'kind']);
        try {
            $proposal = $service->quote($request->user(), $operation, $kind, $changes);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withInput()->withErrors(['correction' => $e->getMessage()]);
        }
        $token = Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'operation_id' => $operation->id, 'expires_at' => now()->addMinutes(30)->timestamp,
            'kind' => $kind, 'data' => [...$data, 'changes' => $changes, 'idempotency_key' => (string) Str::uuid(), 'proposal_hash' => hash('sha256', json_encode($proposal))]]));

        return view('cajas.manual-corrections.preview', compact('operation', 'kind', 'proposal', 'token', 'data'));
    }

    public function store(Request $request, string $operation, CashManualCorrectionService $service)
    {
        $operation = $this->operation($request, $operation, $service);
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted']]);
        try {
            $token = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Vista previa inválida.');
        }
        abort_unless($token['user_id'] === $request->user()->id && $token['operation_id'] === $operation->id, 403);
        abort_if($token['expires_at'] < now()->timestamp, 409, 'La vista previa venció.');
        $record = $service->request($request->user(), $operation, $token['kind'], [...$token['data'], 'confirmed' => 1]);

        return redirect()->route('cajas.manual-corrections.show', [$operation, $record])->with('status', 'Solicitud enviada a Herbe. No se movió dinero.');
    }

    public function show(Request $request, string $operation, string $correction, CashManualCorrectionService $service)
    {
        $operation = CashOperation::visibleTo($request->user())->where('public_id', $operation)->firstOrFail();
        $service->authorize($request->user(), $operation);
        $record = $operation->manualCorrections()->where('public_id', $correction)->with(['creator', 'resolver'])->firstOrFail();

        return view('cajas.manual-corrections.show', ['operation' => $operation, 'record' => $record, 'kind' => $record->kind, 'proposal' => $record->proposal, 'sourceToken' => $service->fingerprint($operation)]);
    }

    public function resolve(Request $request, string $operation, string $correction, string $action, CashManualCorrectionService $service)
    {
        $operation = $this->operation($request, $operation, $service, true);
        $record = $operation->manualCorrections()->where('public_id', $correction)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withErrors(['correction' => $e->getMessage()]);
        }

        return redirect()->route('cajas.manual-corrections.show', [$operation, $record])->with('status', $action === 'approve' ? 'Resolución autorizada. Se conservó el original y el historial.' : 'Solicitud rechazada sin mover dinero.');
    }
}
