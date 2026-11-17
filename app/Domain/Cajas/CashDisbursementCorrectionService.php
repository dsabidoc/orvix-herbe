<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashCutItem;
use App\Models\CashDisbursementCorrection;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\CashPendingDisbursement;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashDisbursementCorrectionService
{
    public function authorize(User $user, CashCollectionCut $cut, bool $resolve = false): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
        app(CashCutService::class)->authorize($user, $cut->operator_id, $resolve ? 'cajas.disbursements.correct-resolve' : 'cajas.disbursements.correct-request');
    }

    public function quote(User $user, CashPendingDisbursement $delivery, string $kind, array $input, bool $requester = true): array
    {
        $cut = CashCollectionCut::findOrFail($delivery->cash_collection_cut_id);
        abort_if(app(CashLoanRehearsal::class)->applied($cut), 409, 'La corrección de una entrega integrada requiere coordinación con Préstamos.');
        $this->authorize($user, $cut, ! $requester);
        abort_unless(in_array($kind, ['correct', 'cancel'], true), 404);
        app(CashPendingDisbursementService::class)->validateLedger($delivery, $delivery->credit);
        $before = $delivery->effectiveState();
        abort_if($before['cancelled'], 409, 'Esta entrega ya está cancelada. El historial permanece disponible.');
        if ($requester) {
            $source = CashAccount::findOrFail($before['account_id']);
            app(CashPendingDisbursementService::class)->account($user, $source->public_id);
        }
        if ($kind === 'correct') {
            $data = Validator::make($input, ['account_id' => ['required', 'string'], 'delivered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
                'recipient_name' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'max:500'],
                'amount' => ['prohibited'], 'amount_cents' => ['prohibited']])->validate();
            $target = $requester ? app(CashPendingDisbursementService::class)->account($user, $data['account_id'])
                : CashAccount::where('public_id', $data['account_id'])->firstOrFail();
            abort_if($data['delivered_on'] < max($cut->cut_on->toDateString(), $delivery->credit->terms['start_date']), 422, 'La fecha no puede ser anterior al corte ni al inicio del crédito.');
            $after = ['account_id' => $target->id, 'delivered_on' => $data['delivered_on'], 'recipient_name' => trim($data['recipient_name']),
                'reference' => trim($data['reference']), 'cancelled' => false];
            abort_if($before === $after || $after['recipient_name'] === '' || $after['reference'] === '', 422, 'Indica una corrección concreta y completa.');
            $mode = null;
            $effectiveOn = null; // Reclassification posts on authorization day, never rewrites historical entries.
        } else {
            $data = Validator::make($input, ['cancel_type' => ['required', Rule::in(['not_delivered', 'returned'])],
                'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
                'funds_confirmed' => ['accepted']])->validate();
            abort_if($data['effective_on'] < $before['delivered_on'], 422, 'La anulación o devolución no puede ser anterior a la entrega.');
            $this->assertNoCollections($delivery);
            if ($requester && $data['cancel_type'] === 'returned') {
                app(CashMovementService::class)->authorize($user, CashAccount::findOrFail($before['account_id']), 'income');
            }
            $after = [...$before, 'cancelled' => true];
            $mode = $data['cancel_type'];
            $effectiveOn = $data['effective_on'];
        }
        $entries = $kind === 'cancel' ? [['account_id' => $before['account_id'], 'amount_cents' => $delivery->amount_cents]]
            : ($before['account_id'] !== $after['account_id'] ? [
                ['account_id' => $before['account_id'], 'amount_cents' => $delivery->amount_cents],
                ['account_id' => $after['account_id'], 'amount_cents' => -$delivery->amount_cents],
            ] : []);

        return ['before' => $before, 'after' => $after, 'entries' => $entries, 'cancel_type' => $mode, 'effective_on' => $effectiveOn,
            'capital_cents' => $delivery->amount_cents, 'conversion_id' => $delivery->credit->conversion?->id];
    }

    public function request(User $user, CashPendingDisbursement $delivery, string $kind, array $input): CashDisbursementCorrection
    {
        $cut = CashCollectionCut::findOrFail($delivery->cash_collection_cut_id);
        $this->authorize($user, $cut);
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            'loan_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:500'], 'changes' => ['required', 'array']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['evidence']) === '', 422);
        $fingerprint = hash('sha256', json_encode([$user->id, $delivery->id, $kind, $data]));

        return DB::transaction(function () use ($user, $cut, $delivery, $kind, $data, $fingerprint) {
            $cut = $this->lock($cut);
            $this->authorize($user, $cut);
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $cut->disbursementCorrections()->where('base_version', $data['version'])->firstOrFail();
            }
            abort_unless($cut->version === (int) $data['version'] && ! $cut->hasPendingCorrection(), 409, 'El corte cambió o ya tiene una solicitud pendiente.');
            $proposal = $this->quote($user, $delivery->fresh(), $kind, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['loan_hash']), 409, 'Cambió la entrega. Revisa nuevamente el antes y después.');
            $record = CashDisbursementCorrection::create(['public_id' => (string) Str::ulid(), 'cash_collection_cut_id' => $cut->id,
                'active_cut_id' => $cut->id, 'cash_pending_disbursement_id' => $delivery->id, 'base_version' => $cut->version,
                'kind' => $kind, 'status' => 'pending', 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'input' => $data['changes'], 'proposal' => $proposal, 'created_by' => $user->id]);
            $this->finish($user, $cut, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashDisbursementCorrection $record, string $action, array $input): CashDisbursementCorrection
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $this->authorize($user, $record->cut, true);
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'], 'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
        abort_if($action === 'reject' && trim($data['note'] ?? '') === '', 422, 'Explica el rechazo.');
        $fingerprint = hash('sha256', json_encode([$user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint) {
            $cut = $this->lock($record->cut);
            $this->authorize($user, $cut, true);
            $record = CashDisbursementCorrection::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $record;
            }
            abort_unless($record->status === 'pending' && $record->active_cut_id === $cut->id && $cut->version === (int) $data['version'], 409, 'La solicitud ya se resolvió o el corte cambió.');
            if ($action === 'approve') {
                abort_if($record->created_by === $user->id, 403, 'La solicitud requiere autorización de otra persona.');
                abort_unless($cut->version === $record->base_version + 1, 409, 'Cambió la versión original. Rechaza la propuesta y vuelve a prepararla.');
                $proposal = $this->quote($user, $record->disbursement, $record->kind, $record->input, false);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'La entrega o el préstamo cambió. Revisa nuevamente.');
                $ids = array_unique([$proposal['before']['account_id'], $proposal['after']['account_id']]);
                $accounts = CashAccount::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($accounts as $account) {
                    abort_unless($user->fresh()->can('cajas.accounts.view') && $account->allows($user, 'adjust')
                        && $account->is_test && $account->active && $account->loans_enabled && $account->currency === 'MXN', 403, 'Necesitas alcance de ajuste en las cajas de prueba afectadas.');
                }
                foreach ($proposal['entries'] as $entry) {
                    if ($entry['amount_cents'] < 0) {
                        abort_if((int) $accounts[$entry['account_id']]->entries()->lockForUpdate()->pluck('amount_cents')->sum() < -$entry['amount_cents'], 409, 'La caja correcta no tiene saldo suficiente para reconocer esta salida.');
                    }
                }
                if ($proposal['entries']) {
                    abort_if(CashOperation::where('idempotency_key', $data['idempotency_key'])->exists(), 409, 'Esta clave ya identifica otro movimiento.');
                    $date = $proposal['effective_on'] ?? now('America/Merida')->toDateString();
                    $operation = CashOperation::create(['public_id' => (string) Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint,
                        'kind' => $record->kind === 'cancel' ? 'disbursement_cancel' : 'disbursement_correction', 'status' => 'posted',
                        'source_id' => $proposal['before']['account_id'], 'destination_id' => $record->kind === 'correct' ? $proposal['after']['account_id'] : null,
                        'amount_cents' => $proposal['capital_cents'], 'operated_on' => $date, 'reason' => 'Resolución de capital de prueba · '.$record->public_id, 'created_by' => $user->id]);
                    foreach ($proposal['entries'] as $entry) {
                        CashEntry::create(['cash_operation_id' => $operation->id, 'cash_account_id' => $entry['account_id'], 'amount_cents' => $entry['amount_cents'], 'operated_on' => $date, 'created_at' => now()]);
                    }
                    $record->cash_operation_id = $operation->id;
                    AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class, 'auditable_id' => $operation->id,
                        'before' => [], 'after' => ['status' => 'posted', 'amount_cents' => $operation->amount_cents], 'reason' => $operation->reason, 'related_idempotency_key' => $data['idempotency_key']]);
                }
                if ($record->kind === 'cancel' && ($conversion = $record->disbursement->credit->conversion)) {
                    $this->assertNoCollections($record->disbursement);
                    $conversion->loan->update(['active' => false]);
                }
                if ($cut->status === 'submitted') {
                    $cut->status = 'observed';
                }
            }
            $record->fill(['status' => $action === 'approve' ? 'approved' : 'rejected', 'active_cut_id' => null,
                'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => $data['note'] ?? null])->save();
            $this->finish($user, $cut, $record, $action, $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    private function assertNoCollections(CashPendingDisbursement $delivery): void
    {
        if ($conversion = $delivery->credit->conversion) {
            abort_if(CashCutItem::whereIn('cash_test_installment_id', $conversion->loan->installments()->select('id'))->whereNull('removed_at')->exists(),
                409, 'El préstamo ya tiene cobros o adelantos reportados. No se puede cancelar su capital sin resolver esos movimientos.');
        }
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
        $existing = DB::table('cash_cut_commands')->where('idempotency_key', $key)->first();
        abort_if($existing && ! hash_equals($existing->fingerprint, $fingerprint), 409, 'Esta solicitud ya se usó con otros datos.');

        return (bool) $existing;
    }

    private function finish(User $user, CashCollectionCut $cut, CashDisbursementCorrection $record, string $action, string $key, string $fingerprint): void
    {
        $cut->version++;
        $cut->save();
        DB::table('cash_cut_commands')->insert(['idempotency_key' => $key, 'cash_collection_cut_id' => $cut->id, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.disbursement_correction.'.$action, 'auditable_type' => CashDisbursementCorrection::class,
            'auditable_id' => $record->id, 'before' => [], 'after' => ['status' => $record->status, 'cut_version' => $cut->version, 'cash_operation_id' => $record->cash_operation_id], 'related_idempotency_key' => $key]);
    }

    /** Verify the complete append-only chain against the original debit. */
    public function validateHistory(CashPendingDisbursement $delivery): void
    {
        $state = $delivery->originalState();
        foreach ($delivery->corrections()->where('status', 'approved')->orderBy('id')->get() as $record) {
            $p = $record->proposal;
            abort_unless(! $state['cancelled'] && JsonData::equals($p['before'], $state) && $p['capital_cents'] === $delivery->amount_cents, 409, 'El historial de la entrega no es consistente.');
            $expected = $record->kind === 'cancel' ? [['account_id' => $state['account_id'], 'amount_cents' => $delivery->amount_cents]]
                : ($state['account_id'] !== $p['after']['account_id'] ? [['account_id' => $state['account_id'], 'amount_cents' => $delivery->amount_cents], ['account_id' => $p['after']['account_id'], 'amount_cents' => -$delivery->amount_cents]] : []);
            abort_unless(JsonData::equals($p['entries'], $expected) && $p['after']['cancelled'] === ($record->kind === 'cancel'), 409, 'El efecto de la corrección no coincide.');
            if ($expected) {
                $op = CashOperation::find($record->cash_operation_id);
                $entries = CashEntry::where('cash_operation_id', $record->cash_operation_id)->orderBy('id')->get();
                abort_unless($op && $op->kind === ($record->kind === 'cancel' ? 'disbursement_cancel' : 'disbursement_correction') && $op->status === 'posted'
                    && $op->source_id === $state['account_id'] && $op->destination_id === ($record->kind === 'cancel' ? null : $p['after']['account_id'])
                    && $op->amount_cents === $delivery->amount_cents && $op->created_by === $record->resolved_by
                    && $op->operated_on->toDateString() === ($p['effective_on'] ?? $record->resolved_at->timezone('America/Merida')->toDateString())
                    && $entries->count() === count($expected), 409, 'La resolución no coincide con su operación de caja.');
                foreach ($expected as $index => $entry) {
                    abort_unless($entries[$index]->cash_account_id === $entry['account_id'] && $entries[$index]->amount_cents === $entry['amount_cents']
                        && $entries[$index]->operated_on->equalTo($op->operated_on), 409, 'La resolución no coincide con sus asientos.');
                }
            } else {
                abort_if($record->cash_operation_id !== null, 409, 'Una corrección de datos no debe mover dinero.');
            }
            $state = $p['after'];
        }
    }
}
