<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\User;
use App\Support\JsonData;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CashCutWorkflow
{
    public function totals(CashCollectionCut $cut): array
    {
        $reported = (int) $cut->items()->sum('amount_cents');
        $received = (int) $cut->receipts()->sum('amount_cents') + (int) $cut->receiptAdjustments()->sum('amount_cents');
        $offset = (int) $cut->offsets()->get()->sum(fn ($offset) => $offset->effectiveCents());

        return ['reported' => $reported, 'received' => $received, 'offset' => $offset,
            'remaining' => $reported - $received - $offset,
            'reconciled' => $reported > 0 && $reported === $received + $offset];
    }

    public function receive(User $user, CashCollectionCut $cut, array $input): CashCollectionCut
    {
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'account_id' => ['nullable', 'string'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
            'reference' => ['required', 'string', 'max:500'],
            'offset_ids' => ['sometimes', 'array', 'max:100'], 'offset_ids.*' => ['integer', 'distinct'],
            'confirmed' => ['accepted'],
        ])->validate();
        $data['offset_ids'] = array_map('intval', $data['offset_ids'] ?? []);
        sort($data['offset_ids']);
        abort_unless(trim($data['reference']) !== '', 422, 'Indica la referencia de la entrega.');
        $amount = Money::cents($data['amount']);
        if ($amount > 0) {
            $this->receiptAccount($user, (string) ($data['account_id'] ?? ''));
        }
        if ($data['offset_ids']) {
            $this->authorize($user, $cut, 'cajas.offsets.execute');
            $this->authorize($user, $cut, 'cajas.pending-loans.view');
        }

        return $this->command($user, $cut, $input, 'receive', $data, 'cajas.receipts.record', ['draft', 'observed', 'approved_test'], function (CashCollectionCut $cut) use ($user, $data, $amount, $input): array {
            abort_if($cut->status !== 'draft' && ! empty($data['offset_ids']), 409, 'Una entrega complementaria no ejecuta nuevas compensaciones.');
            $this->validatePreparation($cut);
            app(CashLoanRehearsal::class)->validate($cut);
            abort_if($data['received_on'] < $cut->cut_on->toDateString(), 422, 'La recepción no puede ser anterior a la fecha del corte.');
            $offsets = collect();
            if ($data['offset_ids']) {
                $this->authorize($user, $cut, 'cajas.offsets.execute');
                $this->authorize($user, $cut, 'cajas.pending-loans.view');
                $offsets = $cut->pendingLoans()->whereIn('id', $data['offset_ids'])->lockForUpdate()->get();
                abort_unless($offsets->count() === count($data['offset_ids']), 404);
                foreach ($offsets as $credit) {
                    abort_unless($credit->status === 'authorized' && $credit->planned_funding === 'offset'
                        && ! $credit->disbursement()->exists() && ! $cut->offsets()->where('cash_pending_loan_id', $credit->id)->exists(), 409, 'Revisa los créditos: deben estar autorizados, propuestos para compensar y sin compensación previa.');
                }
            }
            $offsetAmount = (int) $offsets->sum('capital_cents');
            $totals = $this->totals($cut);
            abort_unless($amount + $offsetAmount > 0 && $amount + $offsetAmount <= $totals['remaining'], 422, 'La entrega y las compensaciones deben ser positivas y no exceder el pendiente del corte.');
            $receipt = null;
            if ($amount > 0) {
                // Cut commands lock operator/cut first; the cash ledger then locks the receiving account.
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::table('cash_accounts')->where('public_id', $data['account_id'])->update(['updated_at' => DB::raw('updated_at')]);
                }
                $account = $this->receiptAccount($user, $data['account_id'], true);
                abort_if(CashOperation::where('idempotency_key', $input['idempotency_key'])->exists(), 409, 'Esta identidad ya corresponde a otra operación.');
                $operation = CashOperation::create([
                    'public_id' => (string) Str::ulid(), 'idempotency_key' => $input['idempotency_key'],
                    'fingerprint' => hash('sha256', json_encode([$cut->id, $account->id, $amount, $data])),
                    'kind' => 'cut_receipt', 'status' => 'posted', 'source_id' => $account->id,
                    'amount_cents' => $amount, 'operated_on' => $data['received_on'],
                    'reason' => 'RECEPCIÓN CORTE '.$cut->public_id.' · '.trim($data['reference']), 'created_by' => $user->id,
                ]);
                CashEntry::create(['cash_account_id' => $account->id, 'cash_operation_id' => $operation->id,
                    'amount_cents' => $amount, 'operated_on' => $data['received_on'], 'created_at' => now()]);
                $receipt = $cut->receipts()->create(['public_id' => (string) Str::ulid(), 'cash_account_id' => $account->id,
                    'cash_operation_id' => $operation->id, 'amount_cents' => $amount, 'received_on' => $data['received_on'],
                    'reference' => trim($data['reference']), 'created_by' => $user->id]);
                AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class,
                    'auditable_id' => $operation->id, 'before' => [], 'after' => ['status' => 'posted', 'amount_cents' => $amount, 'cash_cut_receipt_id' => $receipt->id],
                    'reason' => $operation->reason, 'related_idempotency_key' => $operation->idempotency_key]);
            }
            foreach ($offsets as $credit) {
                $cut->offsets()->create(['cash_pending_loan_id' => $credit->id, 'amount_cents' => $credit->capital_cents,
                    'executed_on' => $data['received_on'], 'reference' => trim($data['reference']), 'created_by' => $user->id]);
            }
            $cut->custody_locked_at ??= now();
            $cut->save();

            return ['receipt_id' => $receipt?->id, 'received_cents' => $amount, 'offset_cents' => $offsetAmount];
        });
    }

    public function submit(User $user, CashCollectionCut $cut, array $input): CashCollectionCut
    {
        $data = Validator::make($input, ['confirmed' => ['accepted'], 'note' => ['nullable', 'string', 'max:1000']])->validate();
        $this->authorize($user, $cut, 'cajas.pending-loans.view');

        return $this->command($user, $cut, $input, 'submit', $data, 'cajas.cuts.submit', ['draft', 'observed'], function (CashCollectionCut $cut) use ($user, $data): array {
            $this->authorize($user, $cut, 'cajas.pending-loans.view');
            abort_if($cut->status === 'observed' && trim($data['note'] ?? '') === '', 422, 'Responde a la observación antes de reenviar.');
            $snapshot = $this->reviewSnapshot($cut);
            $cut->reviews()->create(['version' => $cut->version + 1, 'action' => 'submitted', 'note' => $data['note'] ?? null,
                'snapshot' => $snapshot, 'created_by' => $user->id]);
            $cut->status = 'submitted';
            $cut->save();

            return ['snapshot_hash' => hash('sha256', json_encode($snapshot))];
        });
    }

    public function review(User $user, CashCollectionCut $cut, string $action, array $input): CashCollectionCut
    {
        abort_unless(in_array($action, ['observe', 'approve']), 404);
        $data = Validator::make($input, ['confirmed' => ['accepted'], 'note' => [$action === 'observe' ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
        if ($action === 'observe') {
            abort_if(trim($data['note'] ?? '') === '', 422, 'Escribe qué debe aclararse.');
        }
        $this->authorize($user, $cut, 'cajas.cuts.review');
        $this->authorize($user, $cut, 'cajas.pending-loans.view');
        $permission = $action === 'approve' ? 'cajas.cuts.approve-test' : 'cajas.cuts.review';

        return $this->command($user, $cut, $input, $action, $data, $permission, ['submitted'], function (CashCollectionCut $cut) use ($user, $data, $action): array {
            $this->authorize($user, $cut, 'cajas.cuts.review');
            $this->authorize($user, $cut, 'cajas.pending-loans.view');
            $submitted = $cut->reviews()->where('action', 'submitted')->latest('id')->firstOrFail();
            if ($action === 'approve') {
                $current = $this->reviewSnapshot($cut);
                abort_unless(JsonData::equals($current, $submitted->snapshot), 409, 'Cambió la información enviada. Solicita una aclaración; no se autorizó el corte.');
            }
            $applied = $action === 'approve' ? app(CashLoanRehearsal::class)->apply($user, $cut) : 0;
            $cut->reviews()->create(['version' => $cut->version + 1, 'action' => $action === 'approve' ? 'approved_test' : 'observed',
                'note' => $data['note'] ?? null, 'created_by' => $user->id]);
            $cut->status = $action === 'approve' ? 'approved_test' : 'observed';
            $cut->save();

            return ['submitted_review_id' => $submitted->id, 'loan_payments_applied' => $applied];
        });
    }

    public function receiptAccount(User $user, string $id, bool $lock = false): CashAccount
    {
        CajasSandbox::enforce();
        $query = CashAccount::visibleTo($user)->where('public_id', $id);
        $account = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        app(CashMovementService::class)->authorize($user, $account, 'income');
        abort_unless($account->loans_enabled && $account->currency === 'MXN', 403, 'La caja debe admitir cobros de Préstamos en MXN.');

        return $account;
    }

    private function authorize(User $user, CashCollectionCut $cut, string $permission): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, $permission);
    }

    private function command(User $user, CashCollectionCut $cut, array $input, string $action, array $payload, string $permission, array $states, Closure $execute): CashCollectionCut
    {
        $this->authorize($user, $cut, $permission);
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $fingerprint = hash('sha256', json_encode([$user->id, $cut->id, $action, $payload]));

        return DB::transaction(function () use ($user, $cut, $action, $permission, $states, $execute, $data, $fingerprint): CashCollectionCut {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->update(['operator_id' => $cut->operator_id]);
            }
            abort_unless(DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->lockForUpdate()->first(), 404);
            $this->authorize($user, $cut, $permission);
            $cut = CashCollectionCut::whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('cash_cut_commands')->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Esta solicitud ya se utilizó con otros datos.');

                return $cut;
            }
            abort_unless(in_array($cut->status, $states, true) && $cut->version === (int) $data['version'], 409, 'El corte cambió. Actualiza la pantalla antes de continuar.');
            abort_if($action === 'receive' && app(CashLoanRehearsal::class)->applied($cut), 409, 'Este corte ya quedó aplicado en el ensayo.');
            abort_if($cut->hasPendingCorrection(), 409, 'Resuelve la corrección pendiente antes de continuar.');
            $before = ['status' => $cut->status, 'version' => $cut->version];
            $result = $execute($cut);
            $cut->increment('version');
            DB::table('cash_cut_commands')->insert(['idempotency_key' => $data['idempotency_key'], 'cash_collection_cut_id' => $cut->id,
                'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.cut.'.$action, 'auditable_type' => CashCollectionCut::class,
                'auditable_id' => $cut->id, 'before' => $before, 'after' => ['status' => $cut->status, 'version' => $cut->version, ...$result],
                'related_idempotency_key' => $data['idempotency_key']]);

            return $cut;
        }, 5);
    }

    public function validatePreparation(CashCollectionCut $cut): void
    {
        $items = $cut->items()->with('installment.loan')->orderBy('id')->get();
        abort_if($items->isEmpty(), 422, 'Agrega cobros antes de registrar la entrega o enviar el corte.');
        foreach ($items as $item) {
            $letter = $item->installment;
            $loan = $letter?->loan;
            abort_unless($loan && $loan->active && $loan->operator_id === $cut->operator_id
                && in_array($loan->calculation_method, ['regular', 'rounded']), 409, 'Un préstamo de prueba cambió. Revisa la preparación.');
            $source = ['folio' => $loan->folio, 'client_name' => $loan->client_name, 'number' => $letter->number,
                'due_date' => $letter->due_date->toDateString(), 'principal_cents' => $letter->principal_cents, 'interest_cents' => $letter->interest_cents,
                'loan_updated_at' => $loan->updated_at->toISOString(), 'letter_updated_at' => $letter->updated_at->toISOString()];
            abort_unless(JsonData::equals($source, $item->snapshot), 409, 'Cambió una letra respecto de la captura. Revisa el corte antes de continuar.');
            $reservations = $letter->reservations()->get();
            abort_if($reservations->sum('amount_cents') > $letter->principal_cents + $letter->interest_cents
                || $reservations->where('closes_installment', true)->where('kind', '!=', 'ordinary')->count() > 1, 409, 'La reserva de una letra necesita revisión.');
        }
        foreach ($cut->offsets()->get() as $offset) {
            app(CashOffsetCorrectionService::class)->validateHistory($offset);
        }
        foreach ($cut->disbursements()->with('credit')->get() as $disbursement) {
            app(CashPendingDisbursementService::class)->validateLedger($disbursement, $disbursement->credit);
        }
        foreach ($cut->pendingLoans()->where('status', '!=', 'cancelled')->get() as $credit) {
            abort_unless($credit->status === 'authorized', 422, 'Autoriza o retira las condiciones de los créditos pendientes antes de recibir o enviar.');
            abort_unless(JsonData::equals(app(CashPendingQuote::class)->calculate($credit->terms)['schedule'], $credit->schedule), 409, 'El calendario de un crédito pendiente cambió. Revisa sus condiciones.');
        }
    }

    private function reviewSnapshot(CashCollectionCut $cut): array
    {
        $this->validatePreparation($cut);
        app(CashLoanRehearsal::class)->validate($cut);
        $totals = $this->totals($cut);
        abort_unless($totals['reconciled'], 422, 'Completa la entrega y concilia el corte antes de enviarlo o autorizarlo.');
        $this->validateReceiptLedger($cut);
        foreach ($cut->offsets()->with('pendingLoan')->get() as $offset) {
            $credit = $offset->pendingLoan;
            abort_unless($credit->cash_collection_cut_id === $cut->id && $credit->status === 'authorized'
                && $credit->planned_funding === 'offset' && ! $credit->disbursement()->exists() && $credit->capital_cents === $offset->amount_cents, 409, 'Una compensación no coincide con el crédito autorizado.');
        }

        return ['cut_id' => $cut->id, 'operator_id' => $cut->operator_id, 'cut_on' => $cut->cut_on->toDateString(), 'totals' => $totals,
            'items' => $cut->items()->orderBy('id')->get()->toArray(),
            'credits' => $cut->pendingLoans()->orderBy('id')->get()->toArray(),
            'receipts' => $cut->receipts()->orderBy('id')->get()->toArray(),
            'offsets' => $cut->offsets()->orderBy('id')->get()->toArray(),
            'receipt_adjustments' => $cut->receiptAdjustments()->orderBy('id')->get()->toArray(),
            ...($cut->offsetCorrections()->where('status', 'approved')->exists() ? ['offset_corrections' => $cut->offsetCorrections()->where('status', 'approved')->orderBy('id')->get()->toArray()] : []),
            ...($cut->disbursementCorrections()->where('status', 'approved')->exists() ? ['disbursement_corrections' => $cut->disbursementCorrections()->where('status', 'approved')->orderBy('id')->get()->toArray()] : []),
            ...($cut->disbursements()->exists() ? ['disbursements' => $cut->disbursements()->orderBy('id')->get()->toArray()] : [])];
    }

    public function validateReceiptLedger(CashCollectionCut $cut): void
    {
        foreach ($cut->receipts()->with('operation')->get() as $receipt) {
            $operation = $receipt->operation;
            $entries = $operation ? CashEntry::where('cash_operation_id', $operation->id)->get() : collect();
            abort_unless($operation && $operation->kind === 'cut_receipt' && $operation->status === 'posted'
                && $operation->source_id === $receipt->cash_account_id && $operation->amount_cents === $receipt->amount_cents
                && $operation->operated_on->equalTo($receipt->received_on) && $entries->count() === 1
                && $entries[0]->cash_account_id === $receipt->cash_account_id && $entries[0]->amount_cents === $receipt->amount_cents
                && $entries[0]->operated_on->equalTo($receipt->received_on), 409, 'Una recepción no coincide con su asiento. Requiere revisión.');
        }
        foreach ($cut->receiptAdjustments()->with(['operation', 'receipt', 'correction'])->get() as $adjustment) {
            $op = $adjustment->operation;
            $entries = CashEntry::where('cash_operation_id', $adjustment->cash_operation_id)->get();
            abort_unless($adjustment->receipt->cash_collection_cut_id === $cut->id
                && $adjustment->correction->cash_collection_cut_id === $cut->id && $adjustment->correction->status === 'approved'
                && $op && $op->kind === 'cut_adjustment' && $op->status === 'posted'
                && $op->source_id === $adjustment->receipt->cash_account_id && $op->amount_cents === abs($adjustment->amount_cents)
                && $op->operated_on->equalTo($adjustment->operated_on) && $entries->count() === 1
                && $entries[0]->cash_account_id === $op->source_id && $entries[0]->amount_cents === $adjustment->amount_cents
                && $entries[0]->operated_on->equalTo($adjustment->operated_on), 409, 'Un ajuste no coincide con su recibo o asiento. Requiere revisión.');
        }
        foreach ($cut->receipts()->get() as $receipt) {
            abort_if($receipt->effectiveCents() < 0, 409, 'El importe efectivo de un recibo no puede ser negativo.');
        }
    }
}
