<?php

namespace App\Domain\Cajas;

use App\Models\AuditEvent;
use App\Models\CashCollectionCut;
use App\Models\CashLoanConversion;
use App\Models\CashPendingLoan;
use App\Models\CashTestLoan;
use App\Models\User;
use App\Support\JsonData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CashLoanConversionService
{
    public function authorize(User $user, CashCollectionCut $cut, bool $convert = false): void
    {
        app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
        if ($convert) {
            app(CashCutService::class)->authorize($user, $cut->operator_id, 'cajas.pending-loans.convert-test');
        }
    }

    /** Only prior executed funding is reusable. A proposal or generic cash expense is not proof. */
    public function readiness(CashCollectionCut $cut, CashPendingLoan $credit): array
    {
        abort_unless($credit->cash_collection_cut_id === $cut->id, 404);
        if ($credit->fundingCancelled()) {
            return ['ready' => false, 'reason' => 'Entrega cancelada · Prueba. Conserva su historial y no puede convertirse ni desembolsarse nuevamente.'];
        }
        if ($credit->conversion()->exists()) {
            return ['ready' => false, 'reason' => 'Este crédito ya tiene un préstamo vigente de prueba.'];
        }
        if ($cut->hasPendingCorrection()) {
            return ['ready' => false, 'reason' => 'Primero resuelve la corrección pendiente del corte.'];
        }
        if ($cut->status !== 'approved_test') {
            return ['ready' => false, 'reason' => 'Herbe debe autorizar el corte antes de convertir el crédito.'];
        }
        if ($credit->status !== 'authorized') {
            return ['ready' => false, 'reason' => 'Las condiciones del crédito no están autorizadas.'];
        }
        if (! app(CashCutWorkflow::class)->totals($cut)['reconciled']) {
            return ['ready' => false, 'reason' => 'Hay una diferencia de dinero en el corte. Primero debe conciliarse.'];
        }
        $offset = $cut->offsets()->where('cash_pending_loan_id', $credit->id)->first();
        $disbursement = $credit->disbursement()->first();
        if ($offset && $disbursement) {
            return ['ready' => false, 'reason' => 'Existen dos fuentes de capital incompatibles. Requiere revisión.'];
        }
        if ($credit->planned_funding === 'cash' && $disbursement && ! $offset) {
            return ['ready' => true, 'reason' => 'Entrega desde caja confirmada: se reutilizará sin volver a descontar dinero.'];
        }
        if ($credit->planned_funding !== 'offset' || ! $offset || $disbursement) {
            return ['ready' => false, 'reason' => 'Falta una entrega de capital confirmada. Adriana puede registrarla desde una caja autorizada si se propuso esa forma de entrega.'];
        }

        return ['ready' => true, 'reason' => 'Compensación confirmada: se reutilizará sin volver a descontar dinero.'];
    }

    public function fingerprint(CashCollectionCut $cut, CashPendingLoan $credit): string
    {
        return hash('sha256', json_encode([$cut->id, $cut->version, $credit->fresh()->toArray(),
            $cut->offsets()->where('cash_pending_loan_id', $credit->id)->first()?->toArray(), $credit->disbursement()->first()?->toArray(), $credit->disbursement()->first()?->effectiveState(), $credit->offset()->first()?->effectiveState()]));
    }

    public function convert(User $user, CashCollectionCut $cut, CashPendingLoan $credit, array $input): CashLoanConversion
    {
        $this->authorize($user, $cut, true);
        abort_unless($credit->cash_collection_cut_id === $cut->id, 404);
        $data = Validator::make($input, ['confirmed' => ['accepted'], 'version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'], 'loan_hash' => ['required', 'string', 'size:64']])->validate();
        $fingerprint = hash('sha256', json_encode([$user->id, $cut->id, $credit->id, 'convert-test', $data]));

        return DB::transaction(function () use ($user, $cut, $credit, $data, $fingerprint): CashLoanConversion {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->update(['operator_id' => $cut->operator_id]);
            }
            abort_unless(DB::table('cash_test_operators')->where('operator_id', $cut->operator_id)->lockForUpdate()->first(), 404);
            $cut = CashCollectionCut::whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $cut, true);
            $credit = $cut->pendingLoans()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            abort_if($credit->fundingCancelled(), 409, 'La entrega fue cancelada. No puede convertirse nuevamente.');
            $credit->unsetRelation('disbursement');
            $command = DB::table('cash_cut_commands')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($command) {
                abort_unless(hash_equals($command->fingerprint, $fingerprint), 409, 'Esta solicitud ya se utilizó con otros datos.');

                return $credit->conversion()->firstOrFail();
            }
            // Distinct requests for the same credit also resolve to its single persistent result.
            if ($existing = $credit->conversion()->first()) {
                return $existing;
            }
            abort_unless($cut->version === (int) $data['version'] && hash_equals($this->fingerprint($cut, $credit), $data['loan_hash']), 409, 'La información cambió. Actualiza y revisa el crédito nuevamente.');
            $state = $this->readiness($cut, $credit);
            abort_unless($state['ready'], 409, $state['reason']);
            app(CashCutWorkflow::class)->validateReceiptLedger($cut);
            $quote = app(CashPendingQuote::class)->calculate($credit->terms);
            abort_unless(JsonData::equals($quote['schedule'], $credit->schedule) && $quote['schedule']['capital_cents'] === $credit->capital_cents
                && $quote['terms']['client_name'] === $credit->client_name, 409, 'Las condiciones y el calendario ya no coinciden.');
            $offset = $cut->offsets()->where('cash_pending_loan_id', $credit->id)->first();
            $disbursement = $credit->disbursement()->first();
            $approval = $cut->reviews()->where('action', 'approved_test')->latest('id')->firstOrFail();
            $submitted = $cut->reviews()->where('action', 'submitted')->where('id', '<', $approval->id)->latest('id')->firstOrFail();
            abort_unless(JsonData::equals(collect($submitted->snapshot['credits'])->firstWhere('id', $credit->id), $credit->toArray()), 409, 'El crédito no coincide con lo autorizado en el corte.');
            if ($offset) {
                app(CashOffsetCorrectionService::class)->validateHistory($offset);
                abort_unless(JsonData::equals(collect($submitted->snapshot['offsets'])->firstWhere('id', $offset->id), $offset->toArray())
                    && $offset->amount_cents === $credit->capital_cents && ! $disbursement, 409, 'La compensación no coincide con lo autorizado en el corte.');
            } else {
                abort_unless($disbursement, 409, 'Falta la entrega de capital.');
                app(CashPendingDisbursementService::class)->validateLedger($disbursement, $credit);
            }
            // Preserve every schedule component. Collection of interest-only/fees is a separate integration.
            $collectible = in_array($credit->terms['calculation_method'], ['regular', 'rounded'], true)
                && collect($credit->schedule['installments'])->every(fn ($row) => $row['amount_cents'] === $row['principal_cents'] + $row['interest_cents']);
            $integration = app(CashLoanRehearsal::class);
            $native = $integration->applied($cut) ? $integration->formalize($cut, $credit) : null;
            $publicId = (string) Str::ulid();
            $loan = CashTestLoan::create(['public_id' => $publicId, 'operator_id' => $cut->operator_id,
                'loan_id' => $native?->id, 'folio' => $native?->folio ?? 'TEST-NUEVO-'.$credit->id, 'client_name' => $credit->client_name,
                'calculation_method' => $credit->terms['calculation_method'], 'active' => true, 'collectible' => $collectible,
                'terms' => $credit->terms, 'schedule' => $credit->schedule]);
            if ($collectible && $native) {
                $integration->project($native);
            } elseif ($collectible) {
                foreach ($credit->schedule['installments'] as $row) {
                    $loan->installments()->create(['number' => $row['number'], 'due_date' => $row['due_date'],
                        'principal_cents' => $row['principal_cents'], 'interest_cents' => $row['interest_cents']]);
                }
            }
            $conversion = CashLoanConversion::create(['public_id' => (string) Str::ulid(), 'cash_pending_loan_id' => $credit->id,
                'cash_test_loan_id' => $loan->id, 'cash_cut_offset_id' => $offset?->id, 'cash_pending_disbursement_id' => $disbursement?->id, 'cash_cut_review_id' => $approval->id,
                'cut_version' => $cut->version, 'created_by' => $user->id,
                'snapshot' => ['credit' => $credit->toArray(), 'offset' => $offset?->toArray(), 'disbursement' => $disbursement?->withoutRelations()->toArray(), 'effective_disbursement' => $disbursement?->effectiveState(), 'effective_offset' => $offset?->effectiveState(), 'folio' => $loan->folio, 'collectible' => $collectible, 'loan_id' => $native?->id]]);
            $cut->increment('version');
            DB::table('cash_cut_commands')->insert(['idempotency_key' => $data['idempotency_key'], 'cash_collection_cut_id' => $cut->id,
                'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.cut.convert_credit', 'auditable_type' => CashCollectionCut::class,
                'auditable_id' => $cut->id, 'before' => [], 'after' => ['conversion_id' => $conversion->id, 'cash_test_loan_id' => $loan->id,
                    'cash_pending_loan_id' => $credit->id, 'cash_cut_offset_id' => $offset?->id, 'cash_pending_disbursement_id' => $disbursement?->id, 'money_delta_cents' => 0],
                'related_idempotency_key' => $data['idempotency_key']]);

            return $conversion;
        }, 5);
    }
}
