<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    public const PERMISSIONS = [
        'income' => 'cajas.income.record', 'expense' => 'cajas.expenses.record',
        'send' => 'cajas.transfers.send', 'receive' => 'cajas.transfers.receive',
    ];

    public function create(User $user, CashAccount $account, string $kind, array $input): CashOperation
    {
        CajasSandbox::enforce();
        abort_unless(in_array($kind, ['income', 'expense', 'transfer'], true), 404);
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'operated_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'destination_id' => [$kind === 'transfer' ? 'required' : 'prohibited', 'string'],
            'confirmed' => [$kind === 'transfer' ? 'nullable' : 'accepted'],
            'loan_id' => ['prohibited'], 'weekly_cut_id' => ['prohibited'], 'collection_movement_id' => ['prohibited'],
            'cash_transfer_correction_id' => ['prohibited'], 'cash_manual_correction_id' => ['prohibited'],
            'cash_cut_offset_id' => ['prohibited'], 'cash_offset_correction_id' => ['prohibited'],
            'cash_disbursement_correction_id' => ['prohibited'], 'cash_pending_disbursement_id' => ['prohibited'], 'cash_loan_conversion_id' => ['prohibited'],
            'cash_cut_correction_id' => ['prohibited'], 'cash_cut_receipt_adjustment_id' => ['prohibited'],
            'cash_collection_cut_id' => ['prohibited'], 'cash_cut_receipt_id' => ['prohibited'], 'cash_pending_loan_id' => ['prohibited'],
            'origin' => ['sometimes', Rule::in(['manual'])],
        ])->validate();
        $amount = Money::cents($data['amount']);
        if ($amount <= 0 || trim($data['reason']) === '') {
            throw ValidationException::withMessages(['amount' => 'Indica un importe mayor a cero y un motivo.']);
        }

        $destination = $kind === 'transfer'
            ? $account->destinations()->where('public_id', $data['destination_id'])->firstOrFail() : null;

        return DB::transaction(function () use ($user, $account, $kind, $data, $amount, $destination): CashOperation {
            $accounts = $this->lockAccounts(array_filter([$account->id, $destination?->id]));
            $source = $accounts->get($account->id);
            abort_unless($source, 404);
            $this->authorize($user, $source, $kind === 'transfer' ? 'send' : $kind);
            if ($destination) {
                $destination = $accounts[$destination->id];
                $this->validDestination($source, $destination);
            }
            $fingerprint = hash('sha256', json_encode([$user->id, $source->id, $destination?->id, $kind, $amount, $data['operated_on'], trim($data['reason'])]));
            $existing = CashOperation::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Esta solicitud ya se usó con otros datos. Abre un formulario nuevo.');

                return $existing;
            }
            if ($kind === 'expense') {
                $this->sufficientBalance($source, $amount);
            }
            $operation = CashOperation::create([
                'public_id' => (string) Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'fingerprint' => $fingerprint,
                'kind' => $kind, 'status' => $kind === 'transfer' ? 'prepared' : 'posted',
                'source_id' => $source->id, 'destination_id' => $destination?->id, 'amount_cents' => $amount,
                'operated_on' => $data['operated_on'], 'reason' => trim($data['reason']), 'created_by' => $user->id,
            ]);
            if ($kind !== 'transfer') {
                $this->entry($operation, $source, $kind === 'income' ? $amount : -$amount, $data['operated_on']);
            }
            $this->audit($user, $operation, 'created', null, $operation->status, $data['reason']);

            return $operation;
        }, 5);
    }

    public function transition(User $user, string $publicId, string $action, array $input): CashOperation
    {
        CajasSandbox::enforce();
        abort_unless(in_array($action, ['deliver', 'accept', 'dispute', 'cancel'], true), 404);
        $data = Validator::make($input, [
            'confirmed' => ['accepted'],
            'effective_on' => [in_array($action, ['deliver', 'accept']) ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
            'reason' => [in_array($action, ['dispute', 'cancel']) ? 'required' : 'nullable', 'string', 'max:500'],
            'amount' => ['prohibited'],
            'destination_id' => ['prohibited'],
        ])->validate();

        $operation = CashOperation::visibleTo($user)->where('public_id', $publicId)->where('kind', 'transfer')->firstOrFail();

        return DB::transaction(function () use ($user, $operation, $action, $data): CashOperation {
            // Scope is checked before exposing the record; locks are always accounts then operation.
            $accounts = $this->lockAccounts([$operation->source_id, $operation->destination_id]);
            $operation = CashOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $source = $accounts[$operation->source_id];
            $destination = $accounts[$operation->destination_id];
            $incoming = in_array($action, ['accept', 'dispute']);
            $this->authorize($user, $incoming ? $destination : $source, $incoming ? 'receive' : 'send');
            if ($action === 'dispute') {
                abort_unless($user->fresh()->can('cajas.transfers.dispute'), 403);
            }
            $before = $operation->status;
            if ($before === 'accepted') {
                abort_if($operation->transferCorrections()->where('status', 'pending')->exists() || $operation->effectiveTransferState()['cancelled'], 409, 'Consulta la solicitud o cancelación autorizada de este traspaso.');
            }
            // Repeat of an already completed action is a no-op, with authorization still checked.
            if (($action === 'deliver' && in_array($before, ['delivered', 'accepted', 'disputed']))
                || ($action === 'accept' && $before === 'accepted')
                || ($action === 'cancel' && $before === 'cancelled')
                || ($action === 'dispute' && $before === 'disputed')) {
                return $operation;
            }
            if ($action === 'deliver') {
                $this->requireStatus($before, ['prepared']);
                $this->validDestination($source, $destination);
                $this->validateDate($data['effective_on'], $operation->operated_on->toDateString());
                $this->sufficientBalance($source, $operation->amount_cents);
                $this->entry($operation, $source, -$operation->amount_cents, $data['effective_on']);
                $operation->fill(['status' => 'delivered', 'delivered_by' => $user->id, 'delivered_on' => $data['effective_on'], 'delivered_at' => now()]);
            } elseif ($action === 'accept') {
                $this->requireStatus($before, ['delivered', 'disputed']);
                if ($before === 'disputed' && trim($data['reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['reason' => 'Explica cómo se resolvió la diferencia antes de aceptar el importe completo.']);
                }
                $this->validateDate($data['effective_on'], $operation->delivered_on->toDateString());
                // A route revoked after delivery must not strand money already in transit.
                $this->entry($operation, $destination, $operation->amount_cents, $data['effective_on']);
                $operation->fill(['status' => 'accepted', 'accepted_by' => $user->id, 'received_on' => $data['effective_on'], 'accepted_at' => now()]);
            } elseif ($action === 'dispute') {
                $this->requireStatus($before, ['delivered']);
                $operation->status = 'disputed';
            } else {
                $this->requireStatus($before, ['prepared']);
                $operation->status = 'cancelled';
            }
            $operation->save();
            $this->audit($user, $operation, $action, $before, $operation->status, $data['reason'] ?? null);

            return $operation;
        }, 5);
    }

    public function authorize(User $user, CashAccount $account, string $action): void
    {
        CajasSandbox::enforce();
        $user = $user->fresh();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at && $user->can('cajas.access')
            && $user->can('cajas.accounts.view') && $user->can(self::PERMISSIONS[$action])
            && $account->allows($user, $action), 403);
        abort_unless($account->active && $account->is_test, 403, 'Solo puedes operar cajas de prueba activas.');
    }

    private function validDestination(CashAccount $source, CashAccount $destination): void
    {
        abort_unless($destination->is_test && $destination->active && $destination->id !== $source->id
            && $destination->currency === $source->currency && $source->destinations()->whereKey($destination->id)->exists(), 422, 'El destino no está habilitado para este traspaso.');
    }

    private function sufficientBalance(CashAccount $account, int $amount): void
    {
        // Current locking read, not an earlier repeatable-read snapshot.
        if ((int) $account->entries()->lockForUpdate()->pluck('amount_cents')->sum() < $amount) {
            throw ValidationException::withMessages(['amount' => 'Saldo disponible insuficiente. No se realizó ningún movimiento.']);
        }
    }

    private function lockAccounts(array $ids): Collection
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Reserve its writer before any snapshot read.
            DB::table('cash_accounts')->whereIn('id', $ids)->update(['updated_at' => DB::raw('updated_at')]);
        }

        return CashAccount::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function validateDate(string $date, string $minimum): void
    {
        if ($date < $minimum) {
            throw ValidationException::withMessages(['effective_on' => 'La fecha no puede ser anterior al paso previo del traspaso.']);
        }
    }

    private function requireStatus(string $status, array $allowed): void
    {
        abort_unless(in_array($status, $allowed, true), 409, 'El estado cambió. Revisa el traspaso antes de continuar.');
    }

    private function entry(CashOperation $operation, CashAccount $account, int $amount, string $date): void
    {
        CashEntry::create(['cash_operation_id' => $operation->id, 'cash_account_id' => $account->id,
            'amount_cents' => $amount, 'operated_on' => $date, 'created_at' => now()]);
    }

    private function audit(User $user, CashOperation $operation, string $action, ?string $before, string $after, ?string $reason): void
    {
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.'.$action,
            'auditable_type' => CashOperation::class, 'auditable_id' => $operation->id,
            'before' => ['status' => $before], 'after' => ['status' => $after, 'amount_cents' => $operation->amount_cents],
            'reason' => $reason, 'related_idempotency_key' => $operation->idempotency_key]);
    }
}
