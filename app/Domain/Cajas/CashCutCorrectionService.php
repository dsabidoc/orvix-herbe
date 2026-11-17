<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashCutCorrection;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\CashTestInstallment;
use App\Models\User;
use App\Support\JsonData;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CashCutCorrectionService
{
    public function authorize(User $user, CashCollectionCut $cut, string $permission): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, $permission);
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
    }

    public function quote(User $user, CashCollectionCut $cut, string $kind, int $target, array $input, bool $requester = true): array
    {
        $this->authorize($user, $cut, $requester ? 'cajas.corrections.request' : 'cajas.corrections.resolve');
        abort_unless($cut->custody_locked_at && in_array($cut->status, ['draft', 'submitted', 'observed', 'approved_test']), 409, 'Este flujo requiere un corte con una entrega registrada.');
        abort_unless(in_array($kind, ['receipt', 'item']), 404);
        abort_if(app(CashLoanRehearsal::class)->applied($cut), 409, 'El corte integrado ya fue aplicado. Su corrección requiere una reversa coordinada con Préstamos.');
        $totals = app(CashCutWorkflow::class)->totals($cut);
        $deltaCash = 0;
        $deltaReported = 0;
        if ($kind === 'receipt') {
            app(CashCutWorkflow::class)->validateReceiptLedger($cut);
            $receipt = $cut->receipts()->with('account')->whereKey($target)->firstOrFail();
            if ($requester) {
                app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.receipts.record');
                app(CashCutWorkflow::class)->receiptAccount($user, $receipt->account->public_id);
            }
            $data = Validator::make($input, ['amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/']])->validate();
            $before = ['label' => 'Recibo '.$receipt->public_id.' · '.$receipt->account->name, 'amount_cents' => $receipt->effectiveCents()];
            $after = ['label' => $before['label'], 'amount_cents' => Money::cents($data['amount']), 'account_id' => $receipt->cash_account_id];
            $deltaCash = $after['amount_cents'] - $before['amount_cents'];
            abort_if($deltaCash === 0, 422, 'El importe propuesto es igual al importe vigente.');
        } else {
            if ($requester) {
                app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.payments.report');
            }
            $old = $target ? $cut->items()->whereKey($target)->firstOrFail() : null;
            if ($requester && $old && $old->kind !== 'ordinary') {
                app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.advances.report');
            }
            if ($old) {
                abort_if($old->installment->reservations()->whereKeyNot($old->id)->where('closes_installment', true)->where('kind', '!=', 'ordinary')->exists(),
                    409, 'Esta letra tiene otro adelanto que depende del pago original. Resuelve primero esa dependencia; no se cambió la reserva.');
            }
            $before = $old ? ['label' => $this->itemLabel($old->snapshot, $old->kind), 'amount_cents' => $old->amount_cents, 'item' => $old->toArray()]
                : ['label' => 'Concepto omitido', 'amount_cents' => 0];
            $data = Validator::make($input, ['remove' => ['sometimes', 'boolean'], 'installment_id' => ['nullable', 'integer'],
                'amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'include_interest' => ['sometimes', 'boolean']])->validate();
            if (filter_var($data['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                abort_unless($old, 422, 'Selecciona el concepto que quieres retirar.');
                $after = ['label' => 'Retirado del corte', 'amount_cents' => 0, 'remove' => true];
            } else {
                $letter = CashTestInstallment::whereKey($data['installment_id'] ?? 0)->whereHas('loan', fn ($q) => $q->where('operator_id', $cut->operator_id)->where('active', true))->with('loan')->firstOrFail();
                abort_unless(in_array($letter->loan->calculation_method, ['regular', 'rounded']), 422, 'Esta modalidad requiere su propio flujo de capital.');
                $available = app(CashCutService::class)->availability($letter, $cut->cut_on->toDateString(), $old?->id);
                $include = filter_var($data['include_interest'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $general = $old?->kind === 'general_capital' && $old->cash_test_installment_id === $letter->id && ! $include;
                if ($available['future'] || $general) {
                    if ($requester) {
                        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.advances.report');
                        if ($include) {
                            app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.advances.include-interest');
                        }
                    }
                    $effect = $general ? 'general_capital' : ($include ? 'capital_interest' : 'capital');
                    $amount = $include ? $available['total'] : $available['principal'];
                } else {
                    abort_if($include, 422, 'Incluir intereses de adelanto corresponde a letras futuras.');
                    $effect = 'ordinary';
                    $amount = filled($data['amount'] ?? null) ? Money::cents($data['amount']) : $available['total'];
                }
                abort_unless($amount > 0 && $amount <= $available['total'], 409, 'La letra está reservada o el importe supera el saldo disponible.');
                $snapshot = ['folio' => $letter->loan->folio, 'client_name' => $letter->loan->client_name, 'number' => $letter->number,
                    'due_date' => $letter->due_date->toDateString(), 'principal_cents' => $letter->principal_cents, 'interest_cents' => $letter->interest_cents,
                    'loan_updated_at' => $letter->loan->updated_at->toISOString(), 'letter_updated_at' => $letter->updated_at->toISOString()];
                $after = ['label' => $this->itemLabel($snapshot, $effect), 'amount_cents' => $amount, 'remove' => false,
                    'installment_id' => $letter->id, 'kind' => $effect, 'closes_installment' => $available['future'] || $general || $amount === $available['total'],
                    'snapshot' => $snapshot, 'availability' => $available];
                abort_if($old && $old->cash_test_installment_id === $letter->id && $old->kind === $effect && $old->amount_cents === $amount && JsonData::equals($old->snapshot, $snapshot), 422, 'El concepto propuesto es igual al vigente.');
            }
            $deltaReported = $after['amount_cents'] - $before['amount_cents'];
        }

        return ['kind' => $kind, 'target_id' => $target, 'before' => $before, 'after' => $after, 'cash_delta_cents' => $deltaCash,
            'reported_delta_cents' => $deltaReported, 'remaining_before' => $totals['remaining'],
            'remaining_after' => $totals['remaining'] + $deltaReported - $deltaCash];
    }

    public function request(User $user, CashCollectionCut $cut, string $kind, int $target, array $input): CashCutCorrection
    {
        $this->authorize($user, $cut, 'cajas.corrections.request');
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'], 'reference' => ['required', 'string', 'max:500'],
            'confirmed' => ['accepted'], 'proposal_hash' => ['required', 'string', 'size:64'], 'changes' => ['required', 'array']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['reference']) === '', 422);
        $fingerprint = hash('sha256', json_encode([$user->id, $cut->id, 'request_correction', $kind, $target, $data]));

        return DB::transaction(function () use ($user, $cut, $kind, $target, $data, $fingerprint): CashCutCorrection {
            $cut = $this->lock($cut);
            $this->authorize($user, $cut, 'cajas.corrections.request');
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $cut->corrections()->where('base_version', $data['version'])->firstOrFail();
            }
            abort_unless($cut->version === (int) $data['version'] && ! $cut->hasPendingCorrection(), 409, 'El corte cambió o ya tiene una corrección pendiente.');
            $proposal = $this->quote($user, $cut, $kind, $target, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['proposal_hash']), 409, 'Cambió la propuesta. Revisa nuevamente el antes y después.');
            $record = $cut->corrections()->create(['public_id' => (string) Str::ulid(), 'active_cut_id' => $cut->id,
                'base_version' => $cut->version, 'status' => 'pending', 'kind' => $kind, 'target_id' => $target,
                'reason' => trim($data['reason']), 'reference' => trim($data['reference']), 'input' => $data['changes'],
                'proposal' => $proposal, 'baseline' => $this->baseline($cut), 'created_by' => $user->id]);
            $this->finish($user, $cut, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashCutCorrection $record, string $action, array $input): CashCutCorrection
    {
        abort_unless(in_array($action, ['approve', 'reject']), 404);
        $this->authorize($user, $record->cut, 'cajas.corrections.resolve');
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'], 'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
        abort_if($action === 'reject' && trim($data['note'] ?? '') === '', 422, 'Explica por qué se rechaza.');
        $fingerprint = hash('sha256', json_encode([$user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint): CashCutCorrection {
            $cut = $this->lock($record->cut);
            $this->authorize($user, $cut, 'cajas.corrections.resolve');
            $record = CashCutCorrection::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $record;
            }
            abort_unless($record->status === 'pending' && $record->active_cut_id === $cut->id && $cut->version === (int) $data['version'], 409, 'La solicitud ya se resolvió o la versión cambió.');
            if ($action === 'approve') {
                abort_unless($cut->version === $record->base_version + 1 && JsonData::equals($this->baseline($cut), $record->baseline), 409, 'Cambió el corte original. Rechaza la propuesta y prepara una nueva revisión.');
                $proposal = $this->quote($user, $cut, $record->kind, $record->target_id, $record->input, false);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'Cambió la letra, la reserva o el importe. No se aplicó la corrección.');
                if ($record->kind === 'receipt') {
                    $this->adjustReceipt($user, $cut, $record, $data['idempotency_key']);
                } else {
                    if ($record->target_id) {
                        $cut->items()->whereKey($record->target_id)->firstOrFail()->update(['removed_at' => now()]);
                    }
                    $after = $proposal['after'];
                    if (! $after['remove']) {
                        $cut->items()->create(['cash_test_installment_id' => $after['installment_id'], 'kind' => $after['kind'],
                            'amount_cents' => $after['amount_cents'], 'closes_installment' => $after['closes_installment'],
                            'snapshot' => $after['snapshot'], 'created_by' => $user->id, 'cash_cut_correction_id' => $record->id]);
                    }
                }
                // Custody-only corrections preserve an earlier test approval. Changed reports need new review.
                if ($cut->status === 'submitted' || ($record->kind === 'item' && $cut->status === 'approved_test')) {
                    $cut->status = 'observed';
                    $cut->save();
                }
            }
            $record->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'active_cut_id' => null,
                'resolved_by' => $user->id, 'resolution_note' => $data['note'] ?? null, 'resolved_at' => now()]);
            $this->finish($user, $cut, $record, $action, $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    private function adjustReceipt(User $user, CashCollectionCut $cut, CashCutCorrection $record, string $key): void
    {
        $receipt = $cut->receipts()->whereKey($record->target_id)->firstOrFail();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_accounts')->where('id', $receipt->cash_account_id)->update(['updated_at' => DB::raw('updated_at')]);
        }
        $account = CashAccount::visibleTo($user)->whereKey($receipt->cash_account_id)->lockForUpdate()->firstOrFail();
        abort_unless($user->fresh()->can('cajas.accounts.view') && $account->allows($user, 'adjust')
            && $account->active && $account->is_test && $account->loans_enabled && $account->currency === 'MXN', 403, 'No tienes autorización de ajuste sobre esta caja de prueba activa.');
        $delta = $record->proposal['cash_delta_cents'];
        abort_if($delta < 0 && (int) $account->entries()->lockForUpdate()->pluck('amount_cents')->sum() < -$delta, 409, 'La caja no tiene saldo suficiente para el ajuste. Concilia los fondos; no se deshicieron traspasos.');
        abort_if(CashOperation::where('idempotency_key', $key)->exists(), 409, 'Esta identidad pertenece a otra operación.');
        $date = now('America/Merida')->toDateString();
        $operation = CashOperation::create(['public_id' => (string) Str::ulid(), 'idempotency_key' => $key,
            'fingerprint' => hash('sha256', json_encode([$record->id, $delta])), 'kind' => 'cut_adjustment', 'status' => 'posted',
            'source_id' => $account->id, 'amount_cents' => abs($delta), 'operated_on' => $date, 'created_by' => $user->id,
            'reason' => 'AJUSTE DE RECIBO '.$receipt->public_id.' · CORRECCIÓN '.$record->public_id]);
        CashEntry::create(['cash_account_id' => $account->id, 'cash_operation_id' => $operation->id, 'amount_cents' => $delta, 'operated_on' => $date, 'created_at' => now()]);
        $cut->receiptAdjustments()->create(['cash_cut_receipt_id' => $receipt->id, 'cash_cut_correction_id' => $record->id,
            'cash_operation_id' => $operation->id, 'amount_cents' => $delta, 'operated_on' => $date, 'created_by' => $user->id]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class, 'auditable_id' => $operation->id,
            'before' => [], 'after' => ['status' => 'posted', 'amount_cents' => $delta, 'cash_cut_correction_id' => $record->id],
            'reason' => $operation->reason, 'related_idempotency_key' => $key]);
    }

    private function baseline(CashCollectionCut $cut): array
    {
        return ['status' => $cut->status, 'operator_id' => $cut->operator_id, 'cut_on' => $cut->cut_on->toDateString(),
            'items' => $cut->items()->orderBy('id')->get()->toArray(), 'credits' => $cut->pendingLoans()->orderBy('id')->get()->toArray(),
            ...($cut->offsetCorrections()->where('status', 'approved')->exists() ? ['offset_corrections' => $cut->offsetCorrections()->where('status', 'approved')->orderBy('id')->get()->toArray()] : []),
            ...($cut->disbursementCorrections()->where('status', 'approved')->exists() ? ['disbursement_corrections' => $cut->disbursementCorrections()->where('status', 'approved')->orderBy('id')->get()->toArray()] : []),
            ...($cut->disbursements()->exists() ? ['disbursements' => $cut->disbursements()->orderBy('id')->get()->toArray()] : []),
            'receipts' => $cut->receipts()->orderBy('id')->get()->toArray(), 'offsets' => $cut->offsets()->orderBy('id')->get()->toArray(),
            'adjustments' => $cut->receiptAdjustments()->orderBy('id')->get()->toArray(), 'reviews' => $cut->reviews()->orderBy('id')->get()->toArray()];
    }

    private function lock(CashCollectionCut $cut): CashCollectionCut
    {
        CajasSandbox::enforce();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->update(['operator_id' => $cut->operator_id]);
        }
        abort_unless(DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->lockForUpdate()->first(), 404);

        return CashCollectionCut::whereKey($cut->id)->lockForUpdate()->firstOrFail();
    }

    private function existing(string $key, string $fingerprint): bool
    {
        $existing = DB::table('cash_cut_commands')->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Esta solicitud ya se utilizó con otros datos.');
        }

        return (bool) $existing;
    }

    private function finish(User $user, CashCollectionCut $cut, CashCutCorrection $record, string $action, string $key, string $fingerprint): void
    {
        $cut->increment('version');
        DB::table('cash_cut_commands')->insert(['idempotency_key' => $key, 'cash_collection_cut_id' => $cut->id, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.correction.'.$action, 'auditable_type' => CashCutCorrection::class,
            'auditable_id' => $record->id, 'before' => ['version' => $cut->version - 1], 'after' => ['version' => $cut->version, 'status' => $record->status],
            'reason' => $action === 'requested' ? $record->reason : $record->resolution_note, 'related_idempotency_key' => $key]);
    }

    private function itemLabel(array $snapshot, string $kind): string
    {
        return $snapshot['client_name'].' · '.$snapshot['folio'].' · Letra '.$snapshot['number'].' · '.
            ['ordinary' => 'Pago de letra', 'capital' => 'Solo capital', 'capital_interest' => 'Capital e intereses', 'general_capital' => 'Capital de últimas letras'][$kind];
    }
}
