<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashCutOffset;
use App\Models\CashEntry;
use App\Models\CashOperation;
use App\Models\CashPendingDisbursement;
use App\Models\CashPendingLoan;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashPendingDisbursementService
{
    public function authorize(User $user, CashCollectionCut $cut): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.disburse-test');
    }

    public function account(User $user, string $publicId, bool $lock = false): CashAccount
    {
        $query = CashAccount::visibleTo($user)->where('public_id', $publicId);
        $account = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        app(CashMovementService::class)->authorize($user, $account, 'expense');
        abort_unless($account->loans_enabled && $account->currency === 'MXN', 403, 'La caja debe permitir Préstamos y operar en MXN.');

        return $account;
    }

    public function readiness(CashCollectionCut $cut, CashPendingLoan $credit): array
    {
        abort_unless($credit->cash_collection_cut_id === $cut->id, 404);
        if ($credit->disbursement()->exists()) {
            return ['ready' => false, 'reason' => $credit->disbursement()->first()->effectiveState()['cancelled'] ? 'La entrega fue cancelada. No se reutiliza ni se vuelve a desembolsar este crédito.' : 'El capital ya fue entregado desde caja.'];
        }
        if ($credit->conversion()->exists() || $credit->status !== 'authorized' || $credit->planned_funding !== 'cash') {
            return ['ready' => false, 'reason' => 'Se requiere un crédito pendiente con condiciones autorizadas y entrega desde caja.'];
        }
        if ($cut->offsets()->where('cash_pending_loan_id', $credit->id)->exists()) {
            return ['ready' => false, 'reason' => 'Este crédito ya tiene una compensación; no puede recibir otro desembolso.'];
        }
        if ($cut->hasPendingCorrection() || ! in_array($cut->status, ['draft', 'observed', 'approved_test'], true)) {
            return ['ready' => false, 'reason' => 'Resuelve la corrección o la revisión pendiente del corte antes de entregar capital.'];
        }
        if ($cut->pendingLoans()->whereNotIn('status', ['authorized', 'cancelled'])->exists()) {
            return ['ready' => false, 'reason' => 'Autoriza o retira las demás condiciones pendientes antes de entregar capital y bloquear la preparación.'];
        }

        return ['ready' => true, 'reason' => 'Entrega el capital completo desde una caja autorizada. El cobro pendiente del operador no se reduce.'];
    }

    public function deliver(User $user, CashCollectionCut $cut, CashPendingLoan $credit, array $input): CashPendingDisbursement
    {
        $this->authorize($user, $cut);
        abort_unless($credit->cash_collection_cut_id === $cut->id, 404);
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'],
            'loan_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted'], 'account_id' => ['required', 'string'],
            'delivered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()],
            'recipient_name' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'max:500'],
            'amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'loan_id' => ['prohibited']])->validate();
        $data['recipient_name'] = trim($data['recipient_name']);
        $data['reference'] = trim($data['reference']);
        abort_if($data['recipient_name'] === '' || $data['reference'] === '', 422, 'Indica quién recibió el capital y su referencia.');
        $fingerprint = hash('sha256', json_encode([$user->id, $cut->id, $credit->id, 'disburse-test', $data['account_id'],
            $data['delivered_on'], $data['recipient_name'], $data['reference']]));

        return DB::transaction(function () use ($user, $cut, $credit, $data, $fingerprint): CashPendingDisbursement {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->update(['operator_id' => $cut->operator_id]);
            }
            abort_unless(DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->lockForUpdate()->first(), 404);
            $cut = CashCollectionCut::whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $cut);
            $credit = $cut->pendingLoans()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $account = $this->account($user, $data['account_id'], true);
            abort_if($credit->disbursement()->first()?->effectiveState()['cancelled'] ?? false, 409, 'La entrega fue cancelada. Consulta su historial; no se volverá a desembolsar.');
            $command = DB::table('cash_cut_commands')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($command) {
                abort_unless(hash_equals($command->fingerprint, $fingerprint), 409, 'Esta solicitud ya se utilizó con otros datos.');

                return $credit->disbursement()->firstOrFail();
            }
            if ($existing = $credit->disbursement()->with('operation')->first()) {
                abort_unless(hash_equals($existing->operation->fingerprint, $fingerprint), 409, 'El capital ya fue entregado con otros datos. Consulta el comprobante original.');

                return $existing;
            }
            abort_unless($cut->version === (int) $data['version'] && hash_equals(app(CashLoanConversionService::class)->fingerprint($cut, $credit), $data['loan_hash']), 409, 'El crédito o el corte cambió. Actualiza antes de entregar.');
            $state = $this->readiness($cut, $credit);
            abort_unless($state['ready'], 409, $state['reason']);
            app(CashCutWorkflow::class)->validatePreparation($cut);
            app(CashCutWorkflow::class)->validateReceiptLedger($cut);
            abort_unless($credit->capital_cents > 0 && $credit->capital_cents === $credit->schedule['capital_cents']
                && $credit->client_name === $credit->terms['client_name'], 409, 'El capital o el cliente ya no coincide con las condiciones.');
            if ($cut->status === 'approved_test') {
                $approval = $cut->reviews()->where('action', 'approved_test')->latest('id')->firstOrFail();
                $submitted = $cut->reviews()->where('action', 'submitted')->where('id', '<', $approval->id)->latest('id')->firstOrFail();
                abort_unless(JsonData::equals(collect($submitted->snapshot['credits'])->firstWhere('id', $credit->id), $credit->toArray()), 409, 'El crédito cambió después de la revisión de Herbe.');
            }
            abort_if($data['delivered_on'] < max($cut->cut_on->toDateString(), $credit->terms['start_date']), 422, 'La entrega no puede ser anterior al corte ni al inicio del crédito.');
            if ((int) $account->entries()->lockForUpdate()->pluck('amount_cents')->sum() < $credit->capital_cents) {
                throw ValidationException::withMessages(['account_id' => 'Saldo disponible insuficiente. No se entregó capital ni se creó un movimiento.']);
            }
            abort_if(CashOperation::where('idempotency_key', $data['idempotency_key'])->exists(), 409, 'Esta solicitud ya se utilizó en un movimiento de caja.');
            $publicId = (string) Str::ulid();
            // No personal details in the general cash ledger; credit details require their own scope.
            $operation = CashOperation::create(['public_id' => (string) Str::ulid(), 'idempotency_key' => $data['idempotency_key'],
                'fingerprint' => $fingerprint, 'kind' => 'pending_disbursement', 'status' => 'posted', 'source_id' => $account->id,
                'amount_cents' => $credit->capital_cents, 'operated_on' => $data['delivered_on'],
                'reason' => 'Entrega de capital de prueba · '.$publicId, 'created_by' => $user->id]);
            CashEntry::create(['cash_operation_id' => $operation->id, 'cash_account_id' => $account->id,
                'amount_cents' => -$credit->capital_cents, 'operated_on' => $data['delivered_on'], 'created_at' => now()]);
            $record = CashPendingDisbursement::create(['public_id' => $publicId, 'cash_pending_loan_id' => $credit->id,
                'cash_collection_cut_id' => $cut->id, 'cash_account_id' => $account->id, 'cash_operation_id' => $operation->id,
                'amount_cents' => $credit->capital_cents, 'delivered_on' => $data['delivered_on'], 'recipient_name' => $data['recipient_name'],
                'reference' => $data['reference'], 'snapshot' => $credit->toArray(), 'created_by' => $user->id]);
            $cut->custody_locked_at ??= now();
            $cut->version++;
            $cut->save();
            DB::table('cash_cut_commands')->insert(['idempotency_key' => $data['idempotency_key'], 'cash_collection_cut_id' => $cut->id,
                'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.money.created', 'auditable_type' => CashOperation::class,
                'auditable_id' => $operation->id, 'before' => [], 'after' => ['status' => 'posted', 'amount_cents' => $credit->capital_cents],
                'reason' => $operation->reason, 'related_idempotency_key' => $data['idempotency_key']]);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.cut.disburse_credit', 'auditable_type' => CashCollectionCut::class,
                'auditable_id' => $cut->id, 'before' => [], 'after' => ['cash_pending_disbursement_id' => $record->id,
                    'cash_pending_loan_id' => $credit->id, 'money_delta_cents' => -$credit->capital_cents, 'version' => $cut->version],
                'related_idempotency_key' => $data['idempotency_key']]);

            return $record;
        }, 5);
    }

    public function validateLedger(CashPendingDisbursement $record, CashPendingLoan $credit): void
    {
        app(CashDisbursementCorrectionService::class)->validateHistory($record);
        $operation = $record->operation;
        $entries = $operation ? CashEntry::where('cash_operation_id', $operation->id)->get() : collect();
        abort_unless($record->cash_pending_loan_id === $credit->id && $record->cash_collection_cut_id === $credit->cash_collection_cut_id
            && $credit->status === 'authorized' && $credit->planned_funding === 'cash'
            && ! CashCutOffset::where('cash_pending_loan_id', $credit->id)->exists()
            && JsonData::equals($record->snapshot, $credit->fresh()->toArray()) && $record->amount_cents === $credit->capital_cents
            && $record->account?->is_test && $operation && $operation->kind === 'pending_disbursement' && $operation->status === 'posted'
            && $operation->source_id === $record->cash_account_id && $operation->destination_id === null
            && $operation->created_by === $record->created_by && $operation->amount_cents === $record->amount_cents
            && $operation->operated_on->equalTo($record->delivered_on) && $entries->count() === 1
            && $entries[0]->cash_account_id === $record->cash_account_id && $entries[0]->amount_cents === -$record->amount_cents
            && $entries[0]->operated_on->equalTo($record->delivered_on), 409, 'La entrega de capital no coincide con el crédito y su asiento. Requiere revisión.');
    }
}
