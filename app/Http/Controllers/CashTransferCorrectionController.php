<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CashTransferCorrectionService;
use App\Models\CashOperation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashTransferCorrectionController extends Controller
{
    private function transfer(Request $request, string $id, CashTransferCorrectionService $service, bool $resolve = false): CashOperation
    {
        $transfer = CashOperation::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $transfer, $resolve);

        return $transfer;
    }

    public function create(Request $request, string $operation, string $kind, CashTransferCorrectionService $service)
    {
        $transfer = $this->transfer($request, $operation, $service);
        abort_unless(in_array($kind, ['correct', 'cancel']) && ! $transfer->effectiveTransferState()['cancelled'] && ! $transfer->transferCorrections()->where('status', 'pending')->exists(), 409, 'Consulta el historial o resuelve la solicitud pendiente.');

        return view('cajas.transfer-corrections.form', ['transfer' => $transfer, 'kind' => $kind, 'sourceToken' => $service->fingerprint($transfer)]);
    }

    public function preview(Request $request, string $operation, string $kind, CashTransferCorrectionService $service)
    {
        $transfer = $this->transfer($request, $operation, $service);
        $data = $request->validate(['source_token' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:500']]);
        abort_unless(hash_equals($service->fingerprint($transfer), $data['source_token']) && ! $transfer->transferCorrections()->where('status', 'pending')->exists(), 409, 'Cambió el traspaso. Actualiza antes de proponer.');
        $changes = $request->only(['delivered_on', 'received_on', 'reference', 'cancel_type', 'effective_on', 'funds_confirmed', 'amount', 'amount_cents', 'source_id', 'destination_id']);
        try {
            $proposal = $service->quote($request->user(), $transfer, $kind, $changes);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withInput()->withErrors(['correction' => $e->getMessage()]);
        }
        $token = Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'transfer_id' => $transfer->id, 'expires_at' => now()->addMinutes(30)->timestamp,
            'kind' => $kind, 'data' => [...$data, 'changes' => $changes, 'idempotency_key' => (string) Str::uuid(), 'loan_hash' => hash('sha256', json_encode($proposal))]]));

        return view('cajas.transfer-corrections.preview', compact('transfer', 'kind', 'proposal', 'token', 'data'));
    }

    public function store(Request $request, string $operation, CashTransferCorrectionService $service)
    {
        $transfer = $this->transfer($request, $operation, $service);
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted']]);
        try {
            $token = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Vista previa inválida.');
        }
        abort_unless($token['user_id'] === $request->user()->id && $token['transfer_id'] === $transfer->id, 403);
        abort_if($token['expires_at'] < now()->timestamp, 409, 'La vista previa venció.');
        $record = $service->request($request->user(), $transfer, $token['kind'], [...$token['data'], 'confirmed' => 1]);

        return redirect()->route('cajas.transfer-corrections.show', [$transfer, $record])->with('status', 'Solicitud enviada a Herbe. No se movió dinero.');
    }

    public function show(Request $request, string $operation, string $correction, CashTransferCorrectionService $service)
    {
        $transfer = $this->transfer($request, $operation, $service, $request->user()->can('cajas.transfers.correct-resolve'));
        $record = $transfer->transferCorrections()->where('public_id', $correction)->with(['creator', 'resolver'])->firstOrFail();

        return view('cajas.transfer-corrections.show', ['transfer' => $transfer, 'record' => $record, 'kind' => $record->kind, 'proposal' => $record->proposal, 'sourceToken' => $service->fingerprint($transfer)]);
    }

    public function resolve(Request $request, string $operation, string $correction, string $action, CashTransferCorrectionService $service)
    {
        $transfer = $this->transfer($request, $operation, $service, true);
        $record = $transfer->transferCorrections()->where('public_id', $correction)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withErrors(['correction' => $e->getMessage()]);
        }

        return redirect()->route('cajas.transfer-corrections.show', [$transfer, $record])->with('status', $action === 'approve' ? 'Resolución autorizada. Se conservó el original y el historial.' : 'Solicitud rechazada sin mover dinero.');
    }
}
