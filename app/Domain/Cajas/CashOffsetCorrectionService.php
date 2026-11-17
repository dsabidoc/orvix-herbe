<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashCollectionCut;
use App\Models\CashCutItem;
use App\Models\CashCutOffset;
use App\Models\CashOffsetCorrection;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashOffsetCorrectionService
{
    public function authorize(User $user, CashCollectionCut $cut, bool $resolve = false): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
        app(CashCutService::class)->authorize($user, $cut->operator_id, $resolve ? 'cajas.offsets.correct-resolve' : 'cajas.offsets.correct-request');
    }

    public function quote(User $user, CashCutOffset $offset, string $kind, array $input, bool $requester = true): array
    {
        $cut = CashCollectionCut::findOrFail($offset->cash_collection_cut_id);
        abort_if(app(CashLoanRehearsal::class)->applied($cut), 409, 'La corrección de una entrega integrada requiere coordinación con Préstamos.');
        $this->authorize($user, $cut, ! $requester);
        abort_unless(in_array($kind, ['correct', 'cancel'], true), 404);
        abort_unless($cut->custody_locked_at && in_array($cut->status, ['draft', 'submitted', 'observed', 'approved_test']), 409, 'La compensación necesita un corte con recepción registrada.');
        $this->validateHistory($offset);
        $before = $offset->effectiveState();
        abort_if($before['cancelled'], 409, 'Esta compensación ya está cancelada. Su historial permanece disponible.');
        Validator::make($input, ['amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'cash_pending_loan_id' => ['prohibited']])->validate();
        if ($requester) {
            app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.offsets.execute');
        }
        if ($kind === 'correct') {
            $data = Validator::make($input, ['executed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
                'reference' => ['required', 'string', 'max:500']])->validate();
            abort_if($data['executed_on'] < $cut->cut_on->toDateString(), 422, 'La fecha no puede ser anterior al corte.');
            $after = ['executed_on' => $data['executed_on'], 'reference' => trim($data['reference']), 'cancelled' => false];
            abort_if($before === $after || $after['reference'] === '', 422, 'Indica una corrección concreta y completa.');
            $mode = null;
            $effectiveOn = null;
        } else {
            $data = Validator::make($input, ['cancel_type' => ['required', Rule::in(['not_delivered', 'returned_operator'])],
                'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
                'funds_confirmed' => ['accepted']])->validate();
            abort_if($data['effective_on'] < $before['executed_on'], 422, 'La cancelación no puede ser anterior a la compensación.');
            $this->assertNoCollections($offset);
            $after = [...$before, 'cancelled' => true];
            $mode = $data['cancel_type'];
            $effectiveOn = $data['effective_on'];
        }
        app(CashCutWorkflow::class)->validateReceiptLedger($cut);
        $totals = app(CashCutWorkflow::class)->totals($cut);
        $delta = $kind === 'cancel' ? -$offset->amount_cents : 0;

        return ['before' => $before, 'after' => $after, 'cancel_type' => $mode, 'effective_on' => $effectiveOn,
            'capital_cents' => $offset->amount_cents, 'conversion_id' => $offset->pendingLoan->conversion?->id,
            'cash_delta_cents' => 0, 'offset_delta_cents' => $delta, 'remaining_before' => $totals['remaining'],
            'remaining_after' => $totals['remaining'] - $delta];
    }

    public function request(User $user, CashCutOffset $offset, string $kind, array $input): CashOffsetCorrection
    {
        $cut = CashCollectionCut::findOrFail($offset->cash_collection_cut_id);
        $this->authorize($user, $cut);
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            'loan_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:500'], 'changes' => ['required', 'array']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['evidence']) === '', 422);
        $fingerprint = hash('sha256', json_encode(['offset.request', $user->id, $cut->id, $offset->id, $kind, $data]));

        return DB::transaction(function () use ($user, $cut, $offset, $kind, $data, $fingerprint) {
            $cut = $this->lock($cut);
            $this->authorize($user, $cut);
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $cut->offsetCorrections()->where('base_version', $data['version'])->firstOrFail();
            }
            abort_unless($cut->version === (int) $data['version'] && ! $cut->hasPendingCorrection(), 409, 'El corte cambió o ya tiene una solicitud pendiente.');
            $proposal = $this->quote($user, $offset->fresh(), $kind, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['loan_hash']), 409, 'Cambió la compensación. Revisa nuevamente el antes y después.');
            $record = CashOffsetCorrection::create(['public_id' => (string) Str::ulid(), 'cash_collection_cut_id' => $cut->id,
                'active_cut_id' => $cut->id, 'cash_cut_offset_id' => $offset->id, 'base_version' => $cut->version,
                'kind' => $kind, 'status' => 'pending', 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'input' => $data['changes'], 'proposal' => $proposal, 'created_by' => $user->id]);
            $this->finish($user, $cut, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashOffsetCorrection $record, string $action, array $input): CashOffsetCorrection
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $this->authorize($user, $record->cut, true);
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'], 'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
        abort_if($action === 'reject' && trim($data['note'] ?? '') === '', 422, 'Explica el rechazo.');
        $fingerprint = hash('sha256', json_encode(['offset.resolve', $user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint) {
            $cut = $this->lock($record->cut);
            $this->authorize($user, $cut, true);
            $record = CashOffsetCorrection::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($this->existing($data['idempotency_key'], $fingerprint)) {
                return $record;
            }
            abort_unless($record->status === 'pending' && $record->active_cut_id === $cut->id && $cut->version === (int) $data['version'], 409, 'La solicitud ya se resolvió o el corte cambió.');
            if ($action === 'approve') {
                abort_if($record->created_by === $user->id, 403, 'La solicitud requiere autorización de otra persona.');
                abort_unless($cut->version === $record->base_version + 1, 409, 'Cambió la versión original. Rechaza la propuesta y vuelve a prepararla.');
                $proposal = $this->quote($user, $record->offset, $record->kind, $record->input, false);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'La compensación o el préstamo cambió. Revisa nuevamente.');
                if ($record->kind === 'cancel' && ($conversion = $record->offset->pendingLoan->conversion)) {
                    $this->assertNoCollections($record->offset);
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

    private function assertNoCollections(CashCutOffset $offset): void
    {
        if ($conversion = $offset->pendingLoan->conversion) {
            abort_if(CashCutItem::whereIn('cash_test_installment_id', $conversion->loan->installments()->select('id'))->whereNull('removed_at')->exists(),
                409, 'El préstamo ya tiene cobros o adelantos reportados. No se puede cancelar su compensación sin resolver esos movimientos.');
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

    private function finish(User $user, CashCollectionCut $cut, CashOffsetCorrection $record, string $action, string $key, string $fingerprint): void
    {
        $cut->version++;
        $cut->save();
        DB::table('cash_cut_commands')->insert(['idempotency_key' => $key, 'cash_collection_cut_id' => $cut->id, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.offset_correction.'.$action, 'auditable_type' => CashOffsetCorrection::class,
            'auditable_id' => $record->id, 'before' => [], 'after' => ['status' => $record->status, 'cut_version' => $cut->version, 'offset_delta_cents' => $action === 'approve' ? $record->proposal['offset_delta_cents'] : 0, 'cash_delta_cents' => 0], 'related_idempotency_key' => $key]);
    }

    /** Originals and approved corrections form an immutable, non-cash funding chain. */
    public function validateHistory(CashCutOffset $offset): void
    {
        $credit = $offset->pendingLoan()->first();
        abort_unless($credit && $credit->cash_collection_cut_id === $offset->cash_collection_cut_id
            && $credit->status === 'authorized' && $credit->planned_funding === 'offset'
            && ! $credit->disbursement()->exists() && $credit->capital_cents === $offset->amount_cents && $offset->amount_cents > 0,
            409, 'La compensación no coincide con el crédito autorizado.');
        $state = $offset->originalState();
        foreach ($offset->corrections()->where('status', 'approved')->orderBy('id')->get() as $record) {
            $p = $record->proposal;
            $delta = $record->kind === 'cancel' ? -$offset->amount_cents : 0;
            abort_unless(! $state['cancelled'] && in_array($record->kind, ['correct', 'cancel'], true)
                && $record->cash_collection_cut_id === $offset->cash_collection_cut_id && $record->active_cut_id === null
                && $record->resolved_by && $record->resolved_by !== $record->created_by && $record->resolved_at
                && JsonData::equals($p['before'], $state) && $p['capital_cents'] === $offset->amount_cents
                && $p['cash_delta_cents'] === 0 && $p['offset_delta_cents'] === $delta
                && $p['remaining_after'] === $p['remaining_before'] - $delta
                && $p['after']['cancelled'] === ($record->kind === 'cancel'), 409, 'El historial de la compensación no es consistente.');
            if ($record->kind === 'cancel') {
                abort_unless(JsonData::equals($p['after'], [...$state, 'cancelled' => true]), 409, 'Una cancelación no cambia el comprobante.');
            }
            $state = $p['after'];
        }
    }
}
