<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\CashTransferCorrection;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashTransferCorrectionService
{
    public function authorize(User $user, CashOperation $transfer, bool $resolve = false): void
    {
        CajasSandbox::enforce();
        $user = $user->fresh();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at && $user->can('cajas.access')
            && $user->can('cajas.accounts.view') && $user->can($resolve ? 'cajas.transfers.correct-resolve' : 'cajas.transfers.correct-request'), 403);
        abort_unless(CashOperation::visibleTo($user)->whereKey($transfer->id)->exists() && $transfer->kind === 'transfer' && $transfer->status === 'accepted', 404);
        if (! $resolve) {
            app(CashMovementService::class)->authorize($user, CashAccount::findOrFail($transfer->source_id), 'send');
        }
    }

    public function fingerprint(CashOperation $transfer): string
    {
        return hash('sha256', json_encode([$this->originalDigest($transfer->fresh()),
            $transfer->transferCorrections()->orderBy('id')->get()->toArray()]));
    }

    private function originalDigest(CashOperation $transfer): string
    {
        return hash('sha256', json_encode([$transfer->withoutRelations()->toArray(),
            CashEntry::where('cash_operation_id', $transfer->id)->orderBy('id')->get()->toArray()]));
    }

    public function quote(User $user, CashOperation $transfer, string $kind, array $input, bool $requester = true): array
    {
        $this->authorize($user, $transfer, ! $requester);
        abort_unless(in_array($kind, ['correct', 'cancel'], true), 404);
        $this->validateHistory($transfer);
        $before = $transfer->effectiveTransferState();
        abort_if($before['cancelled'], 409, 'Este traspaso ya quedó cancelado. Consulta el historial.');
        Validator::make($input, ['amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'source_id' => ['prohibited'], 'destination_id' => ['prohibited']])->validate();
        if ($kind === 'correct') {
            $data = Validator::make($input, ['delivered_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$transfer->operated_on->toDateString(), 'before_or_equal:'.now('America/Merida')->toDateString()],
                'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:delivered_on', 'before_or_equal:'.now('America/Merida')->toDateString()],
                'reference' => ['required', 'string', 'max:500']])->validate();
            $after = ['delivered_on' => $data['delivered_on'], 'received_on' => $data['received_on'], 'reference' => trim($data['reference']), 'cancelled' => false];
            abort_if($before === $after || $after['reference'] === '', 422, 'Indica una corrección concreta.');
            $mode = null;
            $effectiveOn = null;
            $entries = [];
        } else {
            $data = Validator::make($input, ['cancel_type' => ['required', Rule::in(['not_transferred', 'returned_origin'])], 'funds_confirmed' => ['accepted'],
                'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.max($before['received_on'], $transfer->received_on->toDateString()), 'before_or_equal:'.now('America/Merida')->toDateString()]])->validate();
            // A return is acknowledged by an authorized receiver in the original source, before Herbe resolves it.
            if ($requester && $data['cancel_type'] === 'returned_origin') {
                app(CashMovementService::class)->authorize($user, CashAccount::findOrFail($transfer->source_id), 'receive');
            }
            $after = [...$before, 'cancelled' => true];
            $mode = $data['cancel_type'];
            $effectiveOn = $data['effective_on'];
            $entries = [['account_id' => $transfer->destination_id, 'amount_cents' => -$transfer->amount_cents],
                ['account_id' => $transfer->source_id, 'amount_cents' => $transfer->amount_cents]];
        }

        return ['original_digest' => $this->originalDigest($transfer), 'source_id' => $transfer->source_id, 'destination_id' => $transfer->destination_id,
            'amount_cents' => $transfer->amount_cents, 'before' => $before, 'after' => $after, 'entries' => $entries,
            'cancel_type' => $mode, 'effective_on' => $effectiveOn];
    }

    public function request(User $user, CashOperation $transfer, string $kind, array $input): CashTransferCorrection
    {
        $this->authorize($user, $transfer);
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'],
            'loan_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted'], 'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['required', 'string', 'max:500'], 'changes' => ['required', 'array']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['evidence']) === '', 422);
        $fingerprint = hash('sha256', json_encode(['transfer.request', $user->id, $transfer->id, $kind, $data]));

        return DB::transaction(function () use ($user, $transfer, $kind, $data, $fingerprint) {
            $transfer = $this->lock($transfer);
            $this->authorize($user, $transfer);
            if ($record = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $record;
            }
            abort_unless(hash_equals($this->fingerprint($transfer), $data['source_token']) && ! $transfer->transferCorrections()->where('status', 'pending')->exists(), 409, 'El traspaso cambió o ya tiene una solicitud pendiente.');
            $proposal = $this->quote($user, $transfer, $kind, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['loan_hash']), 409, 'Cambió la propuesta. Revisa nuevamente.');
            $record = CashTransferCorrection::create(['public_id' => (string) Str::ulid(), 'cash_operation_id' => $transfer->id, 'active_transfer_id' => $transfer->id,
                'kind' => $kind, 'status' => 'pending', 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'input' => $data['changes'], 'proposal' => $proposal, 'created_by' => $user->id]);
            $this->finish($user, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashTransferCorrection $record, string $action, array $input): CashTransferCorrection
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $this->authorize($user, $record->transfer, true);
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'], 'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
        abort_if($action === 'reject' && trim($data['note'] ?? '') === '', 422, 'Explica el rechazo.');
        $fingerprint = hash('sha256', json_encode(['transfer.resolve', $user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint) {
            $transfer = $this->lock($record->transfer);
            $this->authorize($user, $transfer, true);
            $record = CashTransferCorrection::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            abort_unless($record->status === 'pending' && $record->active_transfer_id === $transfer->id
                && hash_equals($this->fingerprint($transfer), $data['source_token']), 409, 'La solicitud ya se resolvió o cambió el traspaso.');
            if ($action === 'approve') {
                abort_if($record->created_by === $user->id, 403, 'Otra persona debe autorizar la solicitud.');
                $proposal = $this->quote($user, $transfer, $record->kind, $record->input, false);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'El original o su historial cambió. Rechaza y revisa la propuesta.');
                foreach ([$transfer->source_id, $transfer->destination_id] as $id) {
                    $account = CashAccount::findOrFail($id);
                    abort_unless($account->active && $account->is_test && $account->allows($user, 'adjust'), 403, 'Necesitas autorización de ajuste en ambas cajas de prueba activas.');
                }
                if ($record->kind === 'cancel') {
                    $destination = CashAccount::findOrFail($transfer->destination_id);
                    abort_if((int) $destination->entries()->lockForUpdate()->pluck('amount_cents')->sum() < $transfer->amount_cents, 409, 'El destino no tiene saldo suficiente. Concilia sus movimientos antes de cancelar; no se permiten saldos negativos.');
                    abort_if(CashOperation::where('idempotency_key', $data['idempotency_key'])->exists(), 409, 'Esta clave ya identifica otro movimiento.');
                    $reverse = CashOperation::create(['public_id' => (string) Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint,
                        'kind' => 'transfer_reversal', 'status' => 'posted', 'source_id' => $transfer->destination_id, 'destination_id' => $transfer->source_id,
                        'amount_cents' => $transfer->amount_cents, 'operated_on' => $proposal['effective_on'], 'reason' => 'CANCELACIÓN DE TRASPASO '.$transfer->public_id, 'created_by' => $user->id]);
                    foreach ($proposal['entries'] as $entry) {
                        CashEntry::create(['cash_operation_id' => $reverse->id, 'cash_account_id' => $entry['account_id'], 'amount_cents' => $entry['amount_cents'], 'operated_on' => $proposal['effective_on'], 'created_at' => now()]);
                    }
                    $record->reversal_operation_id = $reverse->id;
                    AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class, 'auditable_id' => $reverse->id,
                        'before' => [], 'after' => ['status' => 'posted', 'amount_cents' => $reverse->amount_cents, 'original_transfer_id' => $transfer->id], 'related_idempotency_key' => $data['idempotency_key']]);
                }
            }
            $record->fill(['status' => $action === 'approve' ? 'approved' : 'rejected', 'active_transfer_id' => null,
                'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => $data['note'] ?? null])->save();
            $this->finish($user, $record, $action, $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    private function lock(CashOperation $transfer): CashOperation
    {
        CajasSandbox::enforce();
        $ids = [$transfer->source_id, $transfer->destination_id];
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_accounts')->whereIn('id', $ids)->update(['updated_at' => DB::raw('updated_at')]);
        }
        CashAccount::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $fresh = CashOperation::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->source_id === $transfer->source_id && $fresh->destination_id === $transfer->destination_id, 409, 'Cambió el origen o destino.');

        return $fresh;
    }

    private function existing(string $key, string $fingerprint): ?CashTransferCorrection
    {
        $command = DB::table('cash_transfer_commands')->where('idempotency_key', $key)->first();
        abort_if($command && ! hash_equals($command->fingerprint, $fingerprint), 409, 'Esta clave ya se usó con otros datos.');

        return $command ? CashTransferCorrection::findOrFail($command->cash_transfer_correction_id) : null;
    }

    private function finish(User $user, CashTransferCorrection $record, string $action, string $key, string $fingerprint): void
    {
        DB::table('cash_transfer_commands')->insert(['idempotency_key' => $key, 'fingerprint' => $fingerprint, 'cash_transfer_correction_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.transfer_correction.'.$action, 'auditable_type' => CashTransferCorrection::class, 'auditable_id' => $record->id,
            'before' => [], 'after' => ['status' => $record->status, 'original_transfer_id' => $record->cash_operation_id, 'reversal_operation_id' => $record->reversal_operation_id], 'related_idempotency_key' => $key]);
    }

    public function validateHistory(CashOperation $transfer): void
    {
        $entries = CashEntry::where('cash_operation_id', $transfer->id)->orderBy('id')->get();
        $source = CashAccount::find($transfer->source_id);
        $destination = CashAccount::find($transfer->destination_id);
        abort_unless($transfer->kind === 'transfer' && $transfer->status === 'accepted' && $transfer->amount_cents > 0
            && $transfer->delivered_by && $transfer->accepted_by && $transfer->delivered_on && $transfer->received_on
            && $source && $destination && $source->is_test && $destination->is_test && $source->id !== $destination->id && $source->currency === $destination->currency
            && $entries->count() === 2, 409, 'El traspaso no tiene una aceptación y un libro consistentes.');
        foreach ([[$transfer->source_id, -$transfer->amount_cents, $transfer->delivered_on], [$transfer->destination_id, $transfer->amount_cents, $transfer->received_on]] as $index => [$id, $amount, $date]) {
            abort_unless($entries[$index]->cash_account_id === $id && $entries[$index]->amount_cents === $amount && $entries[$index]->operated_on->equalTo($date), 409, 'El original no coincide con sus asientos.');
        }
        $state = $transfer->originalTransferState();
        $digest = $this->originalDigest($transfer);
        foreach ($transfer->transferCorrections()->where('status', 'approved')->orderBy('id')->get() as $record) {
            $p = $record->proposal;
            $expected = $record->kind === 'cancel' ? [['account_id' => $transfer->destination_id, 'amount_cents' => -$transfer->amount_cents], ['account_id' => $transfer->source_id, 'amount_cents' => $transfer->amount_cents]] : [];
            abort_unless(! $state['cancelled'] && in_array($record->kind, ['correct', 'cancel'], true) && $p['original_digest'] === $digest
                && JsonData::equals($p['before'], $state) && $p['amount_cents'] === $transfer->amount_cents && JsonData::equals($p['entries'], $expected)
                && $p['after']['cancelled'] === ($record->kind === 'cancel') && $record->resolved_by && $record->resolved_by !== $record->created_by, 409, 'El historial del traspaso no es consistente.');
            if ($expected) {
                $reverse = $record->reversal;
                $rows = CashEntry::where('cash_operation_id', $record->reversal_operation_id)->orderBy('id')->get();
                abort_unless(JsonData::equals($p['after'], [...$state, 'cancelled' => true]) && $reverse && $reverse->kind === 'transfer_reversal' && $reverse->status === 'posted'
                    && $reverse->source_id === $transfer->destination_id && $reverse->destination_id === $transfer->source_id && $reverse->amount_cents === $transfer->amount_cents
                    && $reverse->created_by === $record->resolved_by && $reverse->operated_on->toDateString() === $p['effective_on'] && $rows->count() === 2, 409, 'La cancelación no coincide con su movimiento vinculado.');
                foreach ($expected as $i => $entry) {
                    abort_unless($rows[$i]->cash_account_id === $entry['account_id'] && $rows[$i]->amount_cents === $entry['amount_cents'] && $rows[$i]->operated_on->equalTo($reverse->operated_on), 409, 'La cancelación no coincide con sus asientos.');
                }
            } else {
                abort_if($record->reversal_operation_id !== null, 409, 'Una corrección de datos no debe mover dinero.');
            }
            $state = $p['after'];
        }
    }
}
