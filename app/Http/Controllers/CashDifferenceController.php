<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CashDifferenceEvidence;
use App\Domain\Cajas\CashDifferenceService;
use App\Models\CashAccount;
use App\Models\CashReconciliation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashDifferenceController extends Controller
{
    private function count(Request $request, string $account, string $reconciliation, CashDifferenceService $service, string $action = 'view'): CashReconciliation
    {
        $account = CashAccount::visibleTo($request->user())->where('public_id', $account)->firstOrFail();
        $count = CashReconciliation::where('cash_account_id', $account->id)->where('public_id', $reconciliation)->firstOrFail();
        $service->authorize($request->user(), $count, $action);

        return $count;
    }

    public function create(Request $request, string $account, string $reconciliation, CashDifferenceService $service)
    {
        $count = $this->count($request, $account, $reconciliation, $service, 'request');
        if ($pending = $count->differenceResolutions()->where('status', 'pending')->first()) {
            return redirect()->route('cajas.differences.show', [$count->account, $count, $pending]);
        }
        abort_if($service->state($count) === 'resolved', 409, 'La diferencia ya tiene una aclaración vigente.');
        $kind = $request->query('kind') === 'counting_error' ? 'counting_error' : 'corrections';

        return view('cajas.differences.form', ['account' => $count->account, 'count' => $count, 'kind' => $kind,
            'groups' => app(CashDifferenceEvidence::class)->candidates($request->user(), $count), 'sourceToken' => $service->fingerprint($count),
            'recounts' => CashReconciliation::where('cash_account_id', $count->cash_account_id)->where('id', '>', $count->id)->where('status', 'closed')->where('difference_cents', 0)->where('created_at', '>=', $count->resolved_at)->latest('id')->get()]);
    }

    public function preview(Request $request, string $account, string $reconciliation, CashDifferenceService $service)
    {
        $count = $this->count($request, $account, $reconciliation, $service, 'request');
        $data = $request->validate(['source_token' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1500'], 'evidence' => ['required', 'string', 'max:1000']]);
        abort_unless(hash_equals($service->fingerprint($count), $data['source_token']), 409, 'Cambió la aclaración. Actualiza la página.');
        $changes = $request->only(['kind', 'correction_ids', 'recount_id', 'confirmed']);
        try {
            $proposal = $service->quote($request->user(), $count, $changes);
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [409, 422], true)) {
                throw $e;
            }

            return back()->withInput()->withErrors(['difference' => $e->getMessage()]);
        }
        $token = Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'count_id' => $count->id, 'expires_at' => now()->addMinutes(30)->timestamp,
            'data' => [...$data, 'changes' => $changes, 'idempotency_key' => (string) Str::uuid(), 'proposal_hash' => hash('sha256', json_encode($proposal))]]));

        return view('cajas.differences.preview', ['count' => $count, 'account' => $count->account, 'proposal' => $proposal, 'kind' => $changes['kind'], 'token' => $token, 'data' => $data]);
    }

    public function store(Request $request, string $account, string $reconciliation, CashDifferenceService $service)
    {
        $count = $this->count($request, $account, $reconciliation, $service, 'request');
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted']]);
        try {
            $token = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Vista previa inválida.');
        }
        abort_unless($token['user_id'] === $request->user()->id && $token['count_id'] === $count->id, 403);
        abort_if($token['expires_at'] < now()->timestamp, 409, 'La vista previa venció.');
        try {
            $record = $service->request($request->user(), $count, $token['data']);
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [409, 422], true)) {
                throw $e;
            }

            return redirect()->route('cajas.differences.create', [$count->account, $count])->withErrors(['difference' => $e->getMessage()]);
        }

        return redirect()->route('cajas.differences.show', [$count->account, $count, $record])->with('status', 'Aclaración enviada a Herbe. No se movió dinero.');
    }

    public function show(Request $request, string $account, string $reconciliation, string $resolution, CashDifferenceService $service)
    {
        $count = $this->count($request, $account, $reconciliation, $service);
        $record = $count->differenceResolutions()->where('public_id', $resolution)->with(['creator', 'resolver'])->firstOrFail();

        return view('cajas.differences.show', ['count' => $count, 'account' => $count->account, 'record' => $record, 'proposal' => $record->proposal,
            'kind' => $record->kind, 'sourceToken' => $service->fingerprint($count), 'differenceState' => $service->state($count)]);
    }

    public function resolve(Request $request, string $account, string $reconciliation, string $resolution, string $action, CashDifferenceService $service)
    {
        $count = $this->count($request, $account, $reconciliation, $service, 'resolve');
        $record = $count->differenceResolutions()->where('public_id', $resolution)->firstOrFail();
        try {
            $service->resolve($request->user(), $record, $action, $request->all());
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [409, 422], true)) {
                throw $e;
            }

            return back()->withErrors(['difference' => $e->getMessage()]);
        }

        return redirect()->route('cajas.differences.show', [$count->account, $count, $record])->with('status', 'Resolución guardada en el historial de la revisión de saldo.');
    }
}
