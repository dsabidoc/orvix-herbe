<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashOperation;
use App\Models\CashReconciliation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashReconciliationService
{
    public function authorize(User $user, CashAccount $account, string $action = 'view'): void
    {
        CajasSandbox::enforce();
        $user = $user->fresh();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at && $user->can('cajas.access')
            && $user->can('cajas.accounts.view') && $account->users()->whereKey($user->id)->exists() && $account->is_test, 403);
        if ($action !== 'view') {
            abort_unless($account->active && $account->allows($user, $action)
                && $user->can($action === 'count' ? 'cajas.reconciliations.record' : 'cajas.reconciliations.close'), 403);
        }
    }

    // Called under the account's writer lock for mutations. Dates never decide membership:
    // a backdated entry posted after a close belongs to the next snapshot.
    public function snapshot(CashAccount $account): array
    {
        $entries = $account->entries()->orderBy('id')->get();
        $previous = CashReconciliation::where('cash_account_id', $account->id)->where('status', 'closed')->latest('id')->first();
        $lastId = (int) ($previous?->snapshot['last_entry_id'] ?? 0);
        $period = $entries->where('id', '>', $lastId);
        $transit = CashOperation::where('kind', 'transfer')->whereIn('status', ['delivered', 'disputed'])
            ->where(fn ($q) => $q->where('source_id', $account->id)->orWhere('destination_id', $account->id))->orderBy('id')->get()
            ->map(fn ($operation) => ['id' => $operation->id, 'public_id' => $operation->public_id, 'direction' => $operation->source_id === $account->id ? 'out' : 'in',
                'amount_cents' => $operation->amount_cents, 'status' => $operation->status])->all();

        return [
            'account_name' => $account->name, 'kind' => $account->kind, 'currency' => $account->currency,
            'previous_close_id' => $previous?->id, 'opening_cents' => $previous?->expected_cents ?? 0,
            'expected_cents' => (int) $entries->sum('amount_cents'), 'entry_count' => $entries->count(),
            'last_entry_id' => (int) ($entries->last()?->id ?? 0),
            'ledger_hash' => hash('sha256', json_encode($entries->map(fn ($e) => $e->getRawOriginal())->all())),
            'income_cents' => (int) $period->where('amount_cents', '>', 0)->sum('amount_cents'),
            'expense_cents' => -(int) $period->where('amount_cents', '<', 0)->sum('amount_cents'),
            'period_entries' => $period->map(fn ($e) => ['id' => $e->id, 'operation_id' => $e->cash_operation_id, 'amount_cents' => $e->amount_cents, 'operated_on' => $e->operated_on->toDateString()])->values()->all(),
            'transit' => $transit,
            'outgoing_cents' => array_sum(array_column(array_filter($transit, fn ($t) => $t['direction'] === 'out'), 'amount_cents')),
            'incoming_cents' => array_sum(array_column(array_filter($transit, fn ($t) => $t['direction'] === 'in'), 'amount_cents')),
        ];
    }

    public function token(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot));
    }

    public function record(User $user, CashAccount $account, array $input): CashReconciliation
    {
        CajasSandbox::enforce();
        $data = Validator::make($input, [
            'counted' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'evidence' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:1000'],
            'source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
        ])->validate();
        $data['evidence'] = trim($data['evidence']);
        $data['note'] = trim($data['note'] ?? '');
        $counted = Money::cents($data['counted']);

        return DB::transaction(function () use ($user, $account, $data, $counted): CashReconciliation {
            $account = $this->lock($account->id);
            $this->authorize($user, $account, 'count');
            $fingerprint = hash('sha256', json_encode([$user->id, $account->id, $counted, $data['evidence'], $data['note'], $data['source_token']]));
            $existing = CashReconciliation::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Esta solicitud ya se usó con otros datos.');

                return $existing;
            }
            abort_if(CashReconciliation::where('active_account_id', $account->id)->exists(), 409, 'Ya hay una revisión de saldo pendiente. Consúltala o retírala antes de repetir el conteo.');
            $snapshot = $this->snapshot($account);
            abort_unless(hash_equals($this->token($snapshot), $data['source_token']), 409, 'Cambió la caja. Actualiza la página y vuelve a contar antes de enviar.');
            $difference = $counted - $snapshot['expected_cents'];
            if ($data['evidence'] === '' || ($difference !== 0 && $data['note'] === '')) {
                throw ValidationException::withMessages(['note' => 'Indica la referencia del conteo y explica la diferencia, aunque todavía esté por aclarar.']);
            }
            $record = CashReconciliation::create([
                'public_id' => (string) Str::ulid(), 'cash_account_id' => $account->id, 'active_account_id' => $account->id,
                'previous_close_id' => $snapshot['previous_close_id'], 'idempotency_key' => $data['idempotency_key'],
                'fingerprint' => $fingerprint, 'source_token' => $data['source_token'], 'status' => 'pending',
                'expected_cents' => $snapshot['expected_cents'], 'counted_cents' => $counted, 'difference_cents' => $difference,
                'snapshot' => $snapshot, 'evidence' => $data['evidence'], 'note' => $data['note'], 'created_by' => $user->id,
            ]);
            $this->audit($user, $record, 'record', $data['idempotency_key']);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashReconciliation $record, string $action, array $input): CashReconciliation
    {
        CajasSandbox::enforce();
        abort_unless(in_array($action, ['close', 'reject', 'withdraw'], true), 404);
        $data = Validator::make($input, ['idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            'note' => ['required', 'string', 'max:1000'], 'difference_confirmed' => ['sometimes', 'accepted'],
        ])->validate();
        $data['note'] = trim($data['note']);
        if ($data['note'] === '') {
            throw ValidationException::withMessages(['note' => 'Explica la resolución.']);
        }

        return DB::transaction(function () use ($user, $record, $action, $data): CashReconciliation {
            $account = $this->lock($record->cash_account_id);
            $this->authorize($user, $account, $action === 'withdraw' ? 'count' : 'close');
            $record = CashReconciliation::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($action === 'withdraw' ? $record->created_by === $user->id : $record->created_by !== $user->id, 403, 'Quien registra el conteo necesita que otra persona autorice el cierre.');
            $fingerprint = hash('sha256', json_encode([$user->id, $record->id, $action, $data['note'], ! empty($data['difference_confirmed'])]));
            $existing = CashReconciliation::where('resolution_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->resolution_fingerprint, $fingerprint), 409, 'Esta resolución ya se usó con otros datos.');

                return $existing;
            }
            abort_unless($record->status === 'pending', 409, 'Esta revisión de saldo ya se resolvió. Consulta su historial.');
            if ($action === 'close') {
                abort_unless(hash_equals($record->source_token, $this->token($this->snapshot($account))), 409, 'Cambió la caja después del conteo. Rechaza o retira esta revisión y vuelve a contar.');
                if ($record->difference_cents !== 0 && empty($data['difference_confirmed'])) {
                    throw ValidationException::withMessages(['difference_confirmed' => 'Reconoce la diferencia antes de cerrar. El saldo no se ajustará.']);
                }
            }
            $record->fill(['status' => match ($action) {
                'close' => 'closed', 'reject' => 'rejected', default => 'withdrawn'
            },
                'active_account_id' => null, 'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => $data['note'],
                'resolution_key' => $data['idempotency_key'], 'resolution_fingerprint' => $fingerprint])->save();
            $this->audit($user, $record, $action, $data['idempotency_key']);

            return $record;
        }, 5);
    }

    private function lock(int $id): CashAccount
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_accounts')->where('id', $id)->update(['updated_at' => DB::raw('updated_at')]);
        }

        return CashAccount::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function audit(User $user, CashReconciliation $record, string $action, string $key): void
    {
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.reconciliation.'.$action, 'auditable_type' => CashReconciliation::class,
            'auditable_id' => $record->id, 'after' => $record->only(['status', 'expected_cents', 'counted_cents', 'difference_cents']),
            'reason' => $record->resolution_note ?? $record->note, 'related_idempotency_key' => $key]);
    }
}
