<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\CashManualCorrection;
use App\Models\CashOperation;
use App\Models\User;
use App\Support\JsonData;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashManualCorrectionService
{
    public function authorize(User $user, CashOperation $operation, string $action = 'view'): void
    {
        CajasSandbox::enforce();
        $user = $user->fresh();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at && $user->can('cajas.access') && $user->can('cajas.accounts.view'), 403);
        abort_unless(CashOperation::visibleTo($user)->whereKey($operation->id)->exists() && in_array($operation->kind, ['income', 'expense'], true)
            && $operation->status === 'posted' && $operation->destination_id === null, 404);
        $account = CashAccount::findOrFail($operation->source_id);
        abort_unless($account->is_test, 403);
        if ($action !== 'view') {
            abort_unless($account->active && $user->can($action === 'request' ? 'cajas.manual.correct-request' : 'cajas.manual.correct-resolve'), 403);
            if ($action === 'request') {
                app(CashMovementService::class)->authorize($user, $account, $operation->kind);
            } else {
                abort_unless($account->allows($user, 'adjust'), 403, 'Necesitas autorización de ajuste en esta caja.');
            }
        }
    }

    private function originalDigest(CashOperation $operation): string
    {
        return hash('sha256', json_encode([$operation->fresh()->withoutRelations()->toArray(), CashEntry::where('cash_operation_id', $operation->id)->orderBy('id')->get()->toArray()]));
    }

    public function fingerprint(CashOperation $operation): string
    {
        return hash('sha256', json_encode([$this->originalDigest($operation->fresh()), $operation->manualCorrections()->orderBy('id')->get()->toArray()]));
    }

    public function quote(User $user, CashOperation $operation, string $kind, array $input, bool $requester = true): array
    {
        $this->authorize($user, $operation, $requester ? 'request' : 'resolve');
        abort_unless(in_array($kind, ['correct', 'cancel'], true), 404);
        $this->validateHistory($operation);
        $before = $operation->effectiveManualState();
        abort_if($before['cancelled'], 409, 'Este movimiento ya quedó cancelado. Consulta el historial.');
        $data = Validator::make($input, [
            'source_id' => ['prohibited'], 'destination_id' => ['prohibited'], 'account_id' => ['prohibited'], 'kind' => ['prohibited'],
            'amount' => [$kind === 'correct' ? 'required' : 'prohibited', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'operated_on' => [$kind === 'correct' ? 'required' : 'prohibited', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
            'reference' => [$kind === 'correct' ? 'required' : 'prohibited', 'string', 'max:500'],
            'cancel_type' => [$kind === 'cancel' ? 'required' : 'prohibited', Rule::in(['not_occurred', 'returned'])],
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->minimumDate($operation), 'before_or_equal:'.now('America/Merida')->toDateString()],
            'funds_confirmed' => ['accepted'],
        ])->validate();
        if ($kind === 'correct') {
            abort_if($data['effective_on'] < $data['operated_on'], 422, 'La fecha del ajuste no puede ser anterior a la fecha corregida.');
            $after = ['amount_cents' => Money::cents($data['amount']), 'operated_on' => $data['operated_on'], 'reference' => trim($data['reference']), 'cancelled' => false];
            abort_if($after['amount_cents'] <= 0 || $after['reference'] === '' || $before === $after, 422, 'Indica una corrección concreta con importe mayor a cero. Para anular todo, solicita cancelación.');
        } else {
            $after = [...$before, 'amount_cents' => 0, 'cancelled' => true];
        }
        $delta = ($operation->kind === 'income' ? 1 : -1) * ($after['amount_cents'] - $before['amount_cents']);

        return ['original_digest' => $this->originalDigest($operation), 'account_id' => $operation->source_id, 'kind' => $operation->kind,
            'before' => $before, 'after' => $after, 'delta_cents' => $delta, 'effective_on' => $data['effective_on'], 'cancel_type' => $data['cancel_type'] ?? null];
    }

    public function minimumDate(CashOperation $operation): string
    {
        $last = $operation->manualCorrections()->where('status', 'approved')->latest('id')->first();

        return max($operation->operated_on->toDateString(), $operation->effectiveManualState()['operated_on'], $last?->proposal['effective_on'] ?? '0000-01-01');
    }

    public function request(User $user, CashOperation $operation, string $kind, array $input): CashManualCorrection
    {
        $this->authorize($user, $operation, 'request');
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'],
            'proposal_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted'], 'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['required', 'string', 'max:500'], 'changes' => ['required', 'array']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['evidence']) === '', 422, 'Indica motivo y respaldo.');
        $fingerprint = hash('sha256', json_encode(['manual.request', $user->id, $operation->id, $kind, $data]));

        return DB::transaction(function () use ($user, $operation, $kind, $data, $fingerprint) {
            $operation = $this->lock($operation);
            $this->authorize($user, $operation, 'request');
            if ($existing = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            abort_unless(hash_equals($this->fingerprint($operation), $data['source_token']) && ! $operation->manualCorrections()->where('status', 'pending')->exists(), 409, 'Cambió el movimiento o ya tiene una solicitud pendiente.');
            $proposal = $this->quote($user, $operation, $kind, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['proposal_hash']), 409, 'Cambió la propuesta. Revisa nuevamente.');
            $record = CashManualCorrection::create(['public_id' => (string) Str::ulid(), 'cash_operation_id' => $operation->id, 'active_operation_id' => $operation->id,
                'kind' => $kind, 'status' => 'pending', 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'input' => $data['changes'], 'proposal' => $proposal, 'created_by' => $user->id]);
            $this->finish($user, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashManualCorrection $record, string $action, array $input): CashManualCorrection
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $this->authorize($user, $record->operation, 'resolve');
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'], 'note' => ['required', 'string', 'max:1000']])->validate();
        abort_if(trim($data['note']) === '', 422, 'Explica la resolución.');
        $fingerprint = hash('sha256', json_encode(['manual.resolve', $user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint) {
            $operation = $this->lock($record->operation);
            $this->authorize($user, $operation, 'resolve');
            abort_if($record->created_by === $user->id, 403, 'Otra persona debe resolver la solicitud.');
            $record = CashManualCorrection::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            abort_unless($record->status === 'pending' && $record->active_operation_id === $operation->id
                && hash_equals($this->fingerprint($operation), $data['source_token']), 409, 'La solicitud ya se resolvió o cambió el movimiento.');
            if ($action === 'approve') {
                $proposal = $this->quote($user, $operation, $record->kind, $record->input, false);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'El original o el historial cambió. Rechaza y revisa la propuesta.');
                $delta = $proposal['delta_cents'];
                $account = CashAccount::findOrFail($operation->source_id);
                if ($delta < 0) {
                    abort_if((int) $account->entries()->lockForUpdate()->pluck('amount_cents')->sum() < -$delta, 409, 'Saldo insuficiente para aplicar esta resolución. Concilia la caja; no se permiten saldos negativos.');
                }
                if ($delta !== 0) {
                    abort_if(CashOperation::where('idempotency_key', $data['idempotency_key'])->exists(), 409, 'Esta clave ya identifica otro movimiento.');
                    $adjustment = CashOperation::create(['public_id' => (string) Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint,
                        'kind' => 'manual_adjustment', 'status' => 'posted', 'source_id' => $account->id, 'amount_cents' => abs($delta),
                        'operated_on' => $proposal['effective_on'], 'reason' => ($record->kind === 'cancel' ? 'CANCELACIÓN' : 'CORRECCIÓN').' DE '.$operation->kindLabel().' '.$operation->public_id, 'created_by' => $user->id]);
                    CashEntry::create(['cash_operation_id' => $adjustment->id, 'cash_account_id' => $account->id, 'amount_cents' => $delta,
                        'operated_on' => $proposal['effective_on'], 'created_at' => now()]);
                    $record->adjustment_operation_id = $adjustment->id;
                    AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class, 'auditable_id' => $adjustment->id,
                        'after' => ['status' => 'posted', 'delta_cents' => $delta, 'original_operation_id' => $operation->id], 'related_idempotency_key' => $data['idempotency_key']]);
                }
            }
            $record->fill(['status' => $action === 'approve' ? 'approved' : 'rejected', 'active_operation_id' => null,
                'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => trim($data['note'])])->save();
            $this->finish($user, $record, $action, $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    private function lock(CashOperation $operation): CashOperation
    {
        CajasSandbox::enforce();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_accounts')->where('id', $operation->source_id)->update(['updated_at' => DB::raw('updated_at')]);
        }
        CashAccount::whereKey($operation->source_id)->lockForUpdate()->firstOrFail();
        $fresh = CashOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->source_id === $operation->source_id, 409, 'Cambió la caja.');

        return $fresh;
    }

    private function existing(string $key, string $fingerprint): ?CashManualCorrection
    {
        $command = DB::table('cash_manual_commands')->where('idempotency_key', $key)->first();
        abort_if($command && ! hash_equals($command->fingerprint, $fingerprint), 409, 'Esta clave ya se usó con otros datos.');

        return $command ? CashManualCorrection::findOrFail($command->cash_manual_correction_id) : null;
    }

    private function finish(User $user, CashManualCorrection $record, string $action, string $key, string $fingerprint): void
    {
        DB::table('cash_manual_commands')->insert(['idempotency_key' => $key, 'fingerprint' => $fingerprint, 'cash_manual_correction_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.manual_correction.'.$action, 'auditable_type' => CashManualCorrection::class, 'auditable_id' => $record->id,
            'after' => ['status' => $record->status, 'original_operation_id' => $record->cash_operation_id, 'adjustment_operation_id' => $record->adjustment_operation_id], 'related_idempotency_key' => $key]);
    }

    public function validateHistory(CashOperation $operation): void
    {
        $entries = CashEntry::where('cash_operation_id', $operation->id)->get();
        $sign = $operation->kind === 'income' ? 1 : -1;
        abort_unless(in_array($operation->kind, ['income', 'expense'], true) && $operation->status === 'posted' && $operation->destination_id === null
            && $operation->amount_cents > 0 && $operation->source?->is_test && $entries->count() === 1
            && $entries[0]->cash_account_id === $operation->source_id && $entries[0]->amount_cents === $sign * $operation->amount_cents
            && $entries[0]->operated_on->equalTo($operation->operated_on), 409, 'El registro manual original no coincide con su asiento.');
        $state = $operation->originalManualState();
        $digest = $this->originalDigest($operation);
        foreach ($operation->manualCorrections()->where('status', 'approved')->orderBy('id')->get() as $record) {
            $p = $record->proposal;
            $delta = $sign * ($p['after']['amount_cents'] - $state['amount_cents']);
            abort_unless(! $state['cancelled'] && JsonData::equals($p['before'], $state) && $p['original_digest'] === $digest && $p['account_id'] === $operation->source_id
                && $p['kind'] === $operation->kind && $p['delta_cents'] === $delta && $record->resolved_by && $record->resolved_by !== $record->created_by
                && in_array($record->kind, ['correct', 'cancel'], true) && $p['after']['cancelled'] === ($record->kind === 'cancel')
                && ($record->kind === 'cancel' ? JsonData::equals($p['after'], [...$state, 'amount_cents' => 0, 'cancelled' => true]) : $p['after']['amount_cents'] > 0), 409, 'El historial manual no es consistente.');
            if ($delta !== 0) {
                $adjustment = $record->adjustment;
                $rows = CashEntry::where('cash_operation_id', $record->adjustment_operation_id)->get();
                abort_unless($adjustment && $adjustment->kind === 'manual_adjustment' && $adjustment->status === 'posted' && $adjustment->source_id === $operation->source_id
                    && $adjustment->destination_id === null && $adjustment->amount_cents === abs($delta) && $adjustment->created_by === $record->resolved_by
                    && $adjustment->operated_on->toDateString() === $p['effective_on'] && $rows->count() === 1 && $rows[0]->cash_account_id === $operation->source_id
                    && $rows[0]->amount_cents === $delta && $rows[0]->operated_on->equalTo($adjustment->operated_on), 409, 'El ajuste vinculado no coincide con el historial.');
            } else {
                abort_if($record->adjustment_operation_id !== null, 409, 'Una corrección de datos no debe mover dinero.');
            }
            $state = $p['after'];
        }
    }
}
