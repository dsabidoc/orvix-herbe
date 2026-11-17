<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashDifferenceResolution;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\CashReconciliation;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CashDifferenceService
{
    public function authorize(User $user, CashReconciliation $count, string $action = 'view'): void
    {
        $account = CashAccount::findOrFail($count->cash_account_id);
        app(CashReconciliationService::class)->authorize($user, $account);
        abort_unless($count->status === 'closed' && $count->difference_cents !== 0, 409, 'Solo se aclaran diferencias de revisiones de saldo cerradas.');
        if ($action !== 'view') {
            $user = $user->fresh();
            abort_unless($account->active && $user->can($action === 'request' ? 'cajas.differences.request' : 'cajas.differences.resolve')
                && $account->allows($user, $action === 'request' ? 'count' : 'close'), 403);
        }
    }

    public function fingerprint(CashReconciliation $count): string
    {
        return hash('sha256', json_encode([$count->fresh()->withoutRelations()->toArray(), $count->differenceResolutions()->orderBy('id')->get()->toArray()]));
    }

    public function state(CashReconciliation $count): string
    {
        if ($count->differenceResolutions()->where('status', 'pending')->exists()) {
            return 'pending';
        }
        $last = $count->differenceResolutions()->where('status', 'approved')->latest('id')->first();
        if (! $last) {
            return 'unresolved';
        }
        try {
            return JsonData::equals($this->evidence($count, $last->kind, $last->input), $last->proposal) ? 'resolved' : 'review';
        } catch (HttpExceptionInterface) {
            return 'review';
        }
    }

    // Evidence may be revised later by its own correction workflow. Never silently
    // continue displaying a currently resolved difference on stale evidence.
    private function evidence(CashReconciliation $count, string $kind, array $input): array
    {
        $prefix = CashEntry::where('cash_account_id', $count->cash_account_id)->where('id', '<=', $count->snapshot['last_entry_id'])->orderBy('id')->get();
        abort_unless(hash_equals($count->snapshot['ledger_hash'], hash('sha256', json_encode($prefix->map(fn ($entry) => $entry->getRawOriginal())->all()))), 409, 'El historial de movimientos no coincide con el saldo registrado al contar.');
        $groups = [];
        $recount = null;
        if ($kind === 'corrections') {
            foreach ($input['correction_ids'] as $key) {
                $groups[] = app(CashDifferenceEvidence::class)->group($count, $key);
            }
            $explained = array_sum(array_column($groups, 'amount_cents'));
            abort_unless($explained === $count->difference_cents, 422, 'Los ajustes no explican la diferencia completa. Revisa los importes; no se moverá dinero para cuadrarla.');
        } else {
            $new = CashReconciliation::where('cash_account_id', $count->cash_account_id)->where('public_id', $input['recount_id'])
                ->where('id', '>', $count->id)->where('status', 'closed')->where('difference_cents', 0)->first();
            abort_unless($new && $new->created_at->greaterThanOrEqualTo($count->resolved_at), 422, 'Selecciona una revisión posterior de la misma caja, cerrada y sin diferencia.');
            $recount = ['id' => $new->id, 'public_id' => $new->public_id, 'counted_cents' => $new->counted_cents,
                'version' => hash('sha256', json_encode($new->withoutRelations()->toArray()))];
            $explained = $count->difference_cents;
        }

        return ['count_digest' => hash('sha256', json_encode($count->fresh()->withoutRelations()->toArray())),
            'difference_cents' => $count->difference_cents, 'explained_cents' => $explained, 'remaining_cents' => 0,
            'groups' => $groups, 'recount' => $recount, 'money_delta_cents' => 0];
    }

    public function quote(User $user, CashReconciliation $count, array $input, bool $resolve = false): array
    {
        $this->authorize($user, $count, $resolve ? 'resolve' : 'request');
        $data = Validator::make($input, ['kind' => ['required', Rule::in(['corrections', 'counting_error'])],
            'correction_ids' => ['sometimes', 'array', 'max:20'], 'correction_ids.*' => ['required', 'string', 'distinct', 'regex:/^(manual|receipt|capital|transfer):[1-9][0-9]*$/'],
            'recount_id' => ['nullable', 'string'], 'confirmed' => ['accepted']])->validate();
        if ($data['kind'] === 'corrections') {
            abort_unless(! empty($data['correction_ids']) && empty($data['recount_id']), 422, 'Selecciona las correcciones que explican la diferencia.');
        } else {
            abort_unless(empty($data['correction_ids']) && ! empty($data['recount_id']), 422, 'Vincula la nueva revisión de saldo sin diferencia para respaldar el error de conteo.');
        }
        $proposal = $this->evidence($count, $data['kind'], $data);
        foreach ($proposal['groups'] as $group) {
            abort_unless(CashOperation::visibleTo($user)->whereKey($group['original_id'])->exists(), 403);
        }

        return $proposal;
    }

    public function request(User $user, CashReconciliation $count, array $input): CashDifferenceResolution
    {
        $this->authorize($user, $count, 'request');
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'],
            'proposal_hash' => ['required', 'string', 'size:64'], 'changes' => ['required', 'array'], 'reason' => ['required', 'string', 'max:1500'], 'evidence' => ['required', 'string', 'max:1000']])->validate();
        abort_if(trim($data['reason']) === '' || trim($data['evidence']) === '', 422, 'Explica la causa y el respaldo.');
        $fingerprint = hash('sha256', json_encode(['difference.request', $user->id, $count->id, $data]));

        return DB::transaction(function () use ($user, $count, $data, $fingerprint) {
            $count = $this->lock($count);
            $this->authorize($user, $count, 'request');
            if ($existing = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            abort_unless(hash_equals($this->fingerprint($count), $data['source_token']) && ! in_array($this->state($count), ['pending', 'resolved'], true), 409, 'La diferencia ya se resolvió, cambió o tiene una aclaración pendiente.');
            $proposal = $this->quote($user, $count, $data['changes']);
            abort_unless(hash_equals(hash('sha256', json_encode($proposal)), $data['proposal_hash']), 409, 'Cambió la evidencia. Revisa la propuesta nuevamente.');
            $record = CashDifferenceResolution::create(['public_id' => (string) Str::ulid(), 'cash_reconciliation_id' => $count->id, 'active_reconciliation_id' => $count->id,
                'kind' => $data['changes']['kind'], 'status' => 'pending', 'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']),
                'input' => $data['changes'], 'proposal' => $proposal, 'created_by' => $user->id]);
            $this->finish($user, $record, 'requested', $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    public function resolve(User $user, CashDifferenceResolution $record, string $action, array $input): CashDifferenceResolution
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $this->authorize($user, $record->reconciliation, 'resolve');
        $data = Validator::make($input, ['source_token' => ['required', 'string', 'size:64'], 'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'], 'note' => ['required', 'string', 'max:1500']])->validate();
        abort_if(trim($data['note']) === '', 422, 'Explica la resolución.');
        $fingerprint = hash('sha256', json_encode(['difference.resolve', $user->id, $record->id, $action, $data]));

        return DB::transaction(function () use ($user, $record, $action, $data, $fingerprint) {
            $count = $this->lock($record->reconciliation);
            $this->authorize($user, $count, 'resolve');
            $record = CashDifferenceResolution::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if($record->created_by === $user->id, 403, 'Otra persona debe resolver la aclaración.');
            if ($existing = $this->existing($data['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            abort_unless($record->status === 'pending' && $record->active_reconciliation_id === $count->id && hash_equals($this->fingerprint($count), $data['source_token']), 409, 'La aclaración cambió o ya se resolvió.');
            if ($action === 'approve') {
                $proposal = $this->quote($user, $count, $record->input, true);
                abort_unless(JsonData::equals($proposal, $record->proposal), 409, 'Cambió una corrección vinculada. Rechaza esta aclaración y prepara otra.');
                foreach ($proposal['groups'] as $group) {
                    foreach ($group['entries'] as $entry) {
                        $claim = DB::table('cash_difference_claims')->where('cash_entry_id', $entry['id'])->first();
                        abort_if($claim && $claim->cash_reconciliation_id !== $count->id, 409, 'Un ajuste ya explica otra revisión de saldo.');
                        if (! $claim) {
                            DB::table('cash_difference_claims')->insert(['cash_entry_id' => $entry['id'], 'cash_reconciliation_id' => $count->id, 'cash_difference_resolution_id' => $record->id, 'created_at' => now()]);
                        }
                    }
                }
            }
            $record->fill(['status' => $action === 'approve' ? 'approved' : 'rejected', 'active_reconciliation_id' => null,
                'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => trim($data['note'])])->save();
            $this->finish($user, $record, $action, $data['idempotency_key'], $fingerprint);

            return $record;
        }, 5);
    }

    private function lock(CashReconciliation $count): CashReconciliation
    {
        CajasSandbox::enforce();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_accounts')->where('id', $count->cash_account_id)->update(['updated_at' => DB::raw('updated_at')]);
        }
        CashAccount::whereKey($count->cash_account_id)->lockForUpdate()->firstOrFail();

        return CashReconciliation::whereKey($count->id)->lockForUpdate()->firstOrFail();
    }

    private function existing(string $key, string $fingerprint): ?CashDifferenceResolution
    {
        $command = DB::table('cash_difference_commands')->where('idempotency_key', $key)->first();
        abort_if($command && ! hash_equals($command->fingerprint, $fingerprint), 409, 'Esta clave ya se usó con otros datos.');

        return $command ? CashDifferenceResolution::findOrFail($command->cash_difference_resolution_id) : null;
    }

    private function finish(User $user, CashDifferenceResolution $record, string $action, string $key, string $fingerprint): void
    {
        DB::table('cash_difference_commands')->insert(['idempotency_key' => $key, 'fingerprint' => $fingerprint, 'cash_difference_resolution_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.difference.'.$action, 'auditable_type' => CashDifferenceResolution::class, 'auditable_id' => $record->id,
            'after' => ['status' => $record->status, 'cash_reconciliation_id' => $record->cash_reconciliation_id, 'money_delta_cents' => 0], 'reason' => $record->resolution_note ?? $record->reason, 'related_idempotency_key' => $key]);
    }
}
