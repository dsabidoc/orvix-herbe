<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\CashCutService;
use App\Domain\Cajas\CashCutWorkflow;
use App\Domain\Cajas\CashLoanRehearsal;
use App\Domain\Cajas\CashPendingQuote;
use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashTestLoan;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CashCutController extends Controller
{
    public function create(Request $request): View
    {
        CajasSandbox::enforce();
        abort_unless($request->user()->can('cajas.collections.loans.view') && $request->user()->can('cajas.cuts.prepare'), 403);

        return view($request->boolean('dialog') ? 'cajas.cuts.create-dialog' : 'cajas.cuts.create', ['operators' => $request->user()->cashOperators()->where('status', 'active')
            ->whereIn('operators.id', DB::table('cash_test_operators')->select('operator_id'))->orderBy('name')->get()]);
    }

    public function store(Request $request, CashCutService $service): RedirectResponse|JsonResponse
    {
        $cut = $service->open($request->user(), $request->all());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $cut->wasRecentlyCreated ? 'Corte de prueba creado.' : 'Ya existe un corte para ese operador y fecha.',
                'detail' => route('cajas.cuts.show', $cut),
                'detailLabel' => 'Abrir corte →',
            ]);
        }

        return redirect()->route('cajas.cuts.show', $cut)->with('status', 'Corte de prueba abierto. Agrega los conceptos de la lista del operador.');
    }

    public function show(Request $request, string $cut, CashCutService $service): View
    {
        $cut = $this->cut($request, $cut, $service);
        $cut->load(['operator', 'items.installment.loan.sourceLoan.vehicle']);
        $pending = $request->user()->can('cajas.pending-loans.view') ? $cut->pendingLoans()->with(['conversion', 'disbursement'])->orderBy('id')->get() : collect();

        return view('cajas.cuts.show', ['cut' => $cut, 'pending' => $pending,
            'totalCents' => (int) $cut->items->sum('amount_cents'),
            'correctionPending' => $cut->hasPendingCorrection(),
            'corrections' => $request->user()->can('cajas.pending-loans.view') && ($request->user()->can('cajas.corrections.request') || $request->user()->can('cajas.corrections.resolve')) ? $cut->corrections()->with(['creator', 'resolver'])->latest('id')->get() : collect(),
            'custody' => app(CashCutWorkflow::class)->totals($cut),
            'receipts' => $cut->receipts()->with(['account', 'creator', 'adjustments.correction'])->orderBy('id')->get(),
            'offsets' => $request->user()->can('cajas.pending-loans.view') ? $cut->offsets()->with(['pendingLoan', 'creator'])->orderBy('id')->get() : collect(),
            'reviews' => $request->user()->can('cajas.pending-loans.view') ? $cut->reviews()->with('creator')->orderBy('id')->get(['id', 'cash_collection_cut_id', 'version', 'action', 'note', 'created_by', 'created_at']) : collect(),
            'offsetCents' => (int) $pending->where('status', 'authorized')->where('planned_funding', 'offset')->sum('capital_cents'),
            'events' => AuditEvent::where('auditable_type', CashCollectionCut::class)->where('auditable_id', $cut->id)
                ->when(! $request->user()->can('cajas.pending-loans.view'), fn ($q) => $q->whereNotIn('action', ['cajas.cut.pending_loan', 'cajas.cut.remove_credit', 'cajas.cut.authorize_credit', 'cajas.cut.convert_credit', 'cajas.cut.disburse_credit']))
                ->with('user:id,name')->orderByDesc('id')->limit(30)->get()]);
    }

    public function conceptForm(Request $request, string $cut, CashCutService $service, string $kind): View
    {
        $cut = $this->cut($request, $cut, $service);
        $service->authorize($request->user(), $cut->operator_id, 'cajas.cuts.prepare');
        $service->authorize($request->user(), $cut->operator_id, $kind === 'letters' ? 'cajas.payments.report' : 'cajas.advances.report');
        abort_unless($cut->status === 'draft' && ! $cut->custody_locked_at && ! $cut->hasPendingCorrection(), 409, 'La preparación ya está bloqueada.');

        $date = $cut->cut_on->toDateString();
        $loans = CashTestLoan::where('operator_id', $cut->operator_id)->where('active', true)->where('collectible', true)
            ->with(['installments.loan', 'sourceLoan.vehicle'])->orderBy('client_name')->get();
        $availability = [];
        foreach ($loans as $loan) {
            foreach ($loan->installments as $letter) {
                $availability[$letter->id] = $service->availability($letter, $date);
            }
        }
        $title = $kind === 'letters' ? 'Agregar letras' : 'Adelanto a capital';

        return view($request->boolean('dialog') ? 'cajas.cuts.concept-dialog' : 'cajas.cuts.concept-form',
            compact('cut', 'kind', 'title', 'loans', 'availability', 'service', 'date'));
    }

    public function letters(Request $request, string $cut, CashCutService $service): RedirectResponse|JsonResponse
    {
        $result = $service->addLetters($request->user(), $this->cut($request, $cut, $service), $request->all());

        return $this->cutSaved($request, $result, 'Conceptos guardados como reportados. No se aplicaron pagos.', 'cut-items', 'Ver conceptos →');
    }

    public function advance(Request $request, string $cut, CashCutService $service): RedirectResponse|JsonResponse
    {
        $result = $service->generalAdvance($request->user(), $this->cut($request, $cut, $service), $request->all());

        return $this->cutSaved($request, $result, 'Adelanto a capital agregado desde las últimas letras completas.', 'cut-items', 'Ver conceptos →');
    }

    public function remove(Request $request, string $cut, string $type, int $item, CashCutService $service): RedirectResponse
    {
        return $this->back($service->remove($request->user(), $this->cut($request, $cut, $service), $type, $item, $request->all()), 'Concepto retirado de la preparación. El historial se conservó.');
    }

    public function creditForm(Request $request, string $cut, CashCutService $service): View
    {
        $cut = $this->creditCut($request, $cut, $service);

        return view($request->boolean('dialog') ? 'cajas.cuts.credit-dialog' : 'cajas.cuts.credit-form', ['cut' => $cut]);
    }

    public function creditAuthorize(Request $request, string $cut, int $item, CashCutService $service): RedirectResponse
    {
        return $this->back($service->authorizePending($request->user(), $this->cut($request, $cut, $service), $item, $request->all()), 'Condiciones autorizadas. El crédito sigue pendiente, sin desembolso.');
    }

    public function quote(Request $request, string $cut, CashCutService $service, CashPendingQuote $calculator): View|JsonResponse
    {
        $cut = $this->creditCut($request, $cut, $service);
        $data = $request->validate(['version' => ['required', 'integer']]);
        abort_unless((int) $data['version'] === $cut->version && $cut->status === 'draft' && ! $cut->custody_locked_at, 409, 'El corte cambió. Actualiza la preparación.');
        $quote = $calculator->calculate($request->all());
        $token = Crypt::encryptString(json_encode(['cut_id' => $cut->id, 'user_id' => $request->user()->id, 'version' => $cut->version,
            'expires_at' => now()->addMinutes(30)->timestamp, 'idempotency_key' => (string) Str::uuid(), 'terms' => $quote['terms'], 'quote_hash' => $quote['hash']]));

        $viewData = compact('cut', 'quote', 'token');
        if ($request->expectsJson()) {
            return response()->json(['preview' => view('cajas.cuts.credit-quote-dialog', $viewData)->render()]);
        }

        $request->session()->flashInput($quote['terms']);

        return view('cajas.cuts.credit-quote', $viewData);
    }

    public function creditStore(Request $request, string $cut, CashCutService $service): RedirectResponse|JsonResponse
    {
        $cut = $this->creditCut($request, $cut, $service);
        $data = $request->validate(['quote_token' => ['required', 'string'], 'confirmed' => ['accepted'], 'authorize' => ['sometimes', 'boolean']]);
        try {
            $quote = json_decode(Crypt::decryptString($data['quote_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'La vista previa no es válida. Genera una nueva.');
        }
        abort_unless($quote['user_id'] === $request->user()->id && $quote['cut_id'] === $cut->id, 403);
        abort_if($quote['expires_at'] < now()->timestamp, 409, 'La vista previa venció. Vuelve a calcularla.');

        $result = $service->pendingLoan($request->user(), $cut, [...$quote, 'authorize' => $request->boolean('authorize')]);
        $message = 'Crédito pendiente guardado. No se creó un préstamo vigente ni se desembolsó dinero.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'detail' => route('cajas.cuts.show', $result).'#cut-credits', 'detailLabel' => 'Ver créditos del corte →']);
        }

        return $this->back($result, $message);
    }

    public function receiptForm(Request $request, string $cut, CashCutService $service): View
    {
        $cut = $this->cut($request, $cut, $service);
        $service->authorize($request->user(), $cut->operator_id, 'cajas.receipts.record');
        abort_unless(in_array($cut->status, ['draft', 'observed', 'approved_test']) && ! $cut->hasPendingCorrection(), 409, 'El corte está en revisión o tiene una corrección pendiente.');
        $offsets = $cut->status === 'draft' && $request->user()->can('cajas.pending-loans.view') && $request->user()->can('cajas.offsets.execute')
            ? $cut->pendingLoans()->where('status', 'authorized')->where('planned_funding', 'offset')
                ->whereNotIn('id', $cut->offsets()->select('cash_pending_loan_id'))->get() : collect();

        return view($request->boolean('dialog') ? 'cajas.cuts.receipt-dialog' : 'cajas.cuts.receipt-form', [
            'cut' => $cut, 'custody' => app(CashCutWorkflow::class)->totals($cut), 'offsets' => $offsets,
            'accounts' => $request->user()->can('cajas.accounts.view') && $request->user()->can('cajas.income.record')
                ? CashAccount::visibleTo($request->user())->where('active', true)->where('is_test', true)->where('loans_enabled', true)
                    ->where('currency', 'MXN')->whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id)->where('cash_account_user.can_income', true))->get() : collect(),
        ]);
    }

    public function receive(Request $request, string $cut, CashCutService $service, CashCutWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $result = $workflow->receive($request->user(), $this->cut($request, $cut, $service), $request->all());
        $message = 'Entrega de prueba registrada. Solo el dinero contado ingresó a la caja; no se aplicaron pagos a Préstamos.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'detail' => route('cajas.cuts.show', $result).'#receipt-review', 'detailLabel' => 'Ver entrega →']);
        }

        return $this->back($result, $message);
    }

    public function reviewForm(Request $request, string $cut, CashCutService $service, CashCutWorkflow $workflow, string $step): View
    {
        $cut = $this->cut($request, $cut, $service);
        $sending = $step === 'send';
        $service->authorize($request->user(), $cut->operator_id, $sending ? 'cajas.cuts.submit' : 'cajas.cuts.review');
        $service->authorize($request->user(), $cut->operator_id, 'cajas.pending-loans.view');
        $custody = $workflow->totals($cut);
        $allowed = $sending
            ? in_array($cut->status, ['draft', 'observed']) && $custody['reconciled']
            : $cut->status === 'submitted';
        abort_unless($allowed && ! $cut->hasPendingCorrection(), 409, 'El estado del corte cambió. Actualiza el corte antes de continuar.');

        return view($request->boolean('dialog') ? 'cajas.cuts.review-dialog' : 'cajas.cuts.review-form', [
            'cut' => $cut,
            'custody' => $custody,
            'sending' => $sending,
            'title' => $sending ? ($cut->status === 'observed' ? 'Reenviar a Herbe' : 'Enviar a Herbe') : 'Revisar corte',
            'observation' => $cut->status === 'observed' ? $cut->reviews()->where('action', 'observed')->latest('id')->value('note') : null,
            'integrated' => $cut->items()->whereHas('installment.loan', fn ($query) => $query->whereNotNull('loan_id'))->exists(),
        ]);
    }

    public function submit(Request $request, string $cut, CashCutService $service, CashCutWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $result = $workflow->submit($request->user(), $this->cut($request, $cut, $service), $request->all());

        return $this->cutSaved($request, $result, 'Corte enviado a Herbe. La versión está bloqueada para revisión; no se movió dinero.', 'cut-review-history', 'Ver historial →');
    }

    public function review(Request $request, string $cut, string $action, CashCutService $service, CashCutWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $result = $workflow->review($request->user(), $this->cut($request, $cut, $service), $action, $request->all());

        $message = match (true) {
            $action === 'observe' => 'Aclaración solicitada. Los recibos y el dinero de prueba se conservan.',
            app(CashLoanRehearsal::class)->applied($result) => 'Corte aplicado en la copia de Préstamos. Se conservaron los recibos de Cajas.',
            default => 'Corte autorizado en la prueba. No se aplicaron pagos, intereses ni retornos en Préstamos.',
        };

        return $this->cutSaved($request, $result, $message, 'cut-review-history', 'Ver historial →');
    }

    private function cut(Request $request, string $id, CashCutService $service): CashCollectionCut
    {
        CajasSandbox::enforce();
        abort_unless($request->user()->can('cajas.collections.loans.view'), 403);
        $cut = CashCollectionCut::visibleTo($request->user())->where('public_id', $id)->firstOrFail();
        $service->authorize($request->user(), $cut->operator_id);

        return $cut;
    }

    private function creditCut(Request $request, string $id, CashCutService $service): CashCollectionCut
    {
        $cut = $this->cut($request, $id, $service);
        $service->authorize($request->user(), $cut->operator_id, 'cajas.pending-loans.create');
        $service->authorize($request->user(), $cut->operator_id, 'cajas.pending-loans.view');
        $service->authorize($request->user(), $cut->operator_id, 'cajas.cuts.prepare');
        abort_unless($cut->status === 'draft' && ! $cut->custody_locked_at, 409, 'La preparación ya está bloqueada.');

        return $cut;
    }

    private function cutSaved(Request $request, CashCollectionCut $cut, string $message, string $anchor, string $label): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'detail' => route('cajas.cuts.show', $cut).'#'.$anchor, 'detailLabel' => $label]);
        }

        return $this->back($cut, $message);
    }

    private function back(CashCollectionCut $cut, string $message): RedirectResponse
    {
        return redirect()->route('cajas.cuts.show', $cut)->with('status', $message);
    }
}
