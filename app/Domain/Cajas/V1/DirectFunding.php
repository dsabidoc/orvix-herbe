<?php

namespace App\Domain\Cajas\V1;

use App\Models\CajasV1PendingCredit;
use App\Models\Operator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class DirectFunding
{
    private const ID = 'regex:/\A[A-Za-z0-9_-]{1,80}\z/';

    public function __construct(
        private IntegrationAccess $access,
        private PilotSignature $signature,
        private PendingCredits $credits,
        private OperatorCutLedger $ledger,
    ) {}

    public function registerCutDisbursements(array $cut, Operator $operator, User $actor): void
    {
        $funded = collect($cut['pending_credits'])->where('funding.type', 'disbursement')->keyBy('pending_credit_id');
        if ($funded->count() !== count($cut['disbursements'])) {
            $this->conflict('Cada desembolso directo debe corresponder a una propuesta de este corte.');
        }
        if ($funded->isEmpty()) {
            return;
        }

        $credits = CajasV1PendingCredit::whereIn('public_id', $funded->keys())->get()->keyBy('public_id');
        $approvedAt = CarbonImmutable::parse($cut['approval']['approved_at']);
        foreach ($cut['disbursements'] as $delivery) {
            $delivery = Arr::only($delivery, [
                'disbursement_id', 'pending_credit_id', 'cash_account_id', 'amount_cents', 'disbursed_on',
                'recipient', 'confirmation_id', 'confirmed_by', 'confirmed_at', 'evidence_ref',
            ]);
            $id = $delivery['pending_credit_id'];
            if (($funded[$id]['funding']['reference_id'] ?? null) !== $delivery['disbursement_id']
                || CarbonImmutable::parse($delivery['confirmed_at'])->isAfter($approvedAt)) {
                $this->conflict('El desembolso debe corresponder a la propuesta y estar confirmado al autorizar el corte.');
            }
            $credit = $credits[$id];
            $credit->setRelation('operator', $operator);
            $this->validateNewReference($delivery['disbursement_id']);
            $this->validateDisbursement($credit, $delivery, $cut['cut_on'], 409);
            $this->ledger->linkCutDisbursement($operator, $actor, $cut, $delivery);
            $credit->update(['disbursement_id' => $delivery['disbursement_id'], 'disbursement' => $delivery]);
        }
    }

    public function apply(CajasV1PendingCredit $credit, array $body, string $hash, string $proof): array
    {
        return DB::transaction(function () use ($credit, $body, $hash, $proof): array {
            $credit = $this->credits->lock($credit);
            if ($this->access->production() && isset($credit->disbursement['cash_account_id'])) {
                $this->access->authorizeCustodyAccount($credit->disbursement['cash_account_id']);
            }
            $existing = DB::table('cajas_v1_funding_events')->where('event_id', $body['event_id'])->first();
            if ($existing) {
                if ($existing->pending_credit_id !== $credit->id || ! hash_equals($existing->body_hash, $hash)) {
                    $this->conflict('El ID de fondeo ya se registró con otro contenido o crédito.');
                }
                if ($this->access->production() && isset($body['cash_account_id']) && is_string($body['cash_account_id'])) {
                    $this->access->authorizeCustodyAccount($body['cash_account_id']);
                }

                return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
            }

            $this->access->requireAdmission();
            $this->verifyProof($credit, $body, $hash, $proof);
            $event = $this->validatedEvent($body);
            if ($this->access->production() && isset($event['cash_account_id'])) {
                $this->access->authorizeCustodyAccount($event['cash_account_id']);
            }
            $current = $credit->funding();
            if ($credit->loan_id !== null || $current['version'] !== $event['expected_funding_version']) {
                $this->conflict('El crédito o la versión vigente no permiten aplicar el evento de fondeo.');
            }

            $reconciliation = match ($event['action']) {
                'confirm' => $this->confirm($credit, $event, $current),
                'hold' => $this->hold($credit, $event, $current),
                'cancel' => $this->cancel($credit, $event, $current),
                'replace' => $this->replace($credit, $event, $current),
            };

            $result = [
                'pending_credit_id' => $credit->public_id, 'event_id' => $event['event_id'], 'status' => $credit->status,
                'currency' => 'MXN', 'funding' => $credit->funding(),
            ];
            if ($credit->observations !== []) {
                $result['observations'] = $credit->observations;
            }
            DB::table('cajas_v1_funding_events')->insert([
                'event_id' => $event['event_id'], 'pending_credit_id' => $credit->id, 'body_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ...($reconciliation ?? []),
            ]);

            return $result;
        }, 5);
    }

    private function hold(CajasV1PendingCredit $credit, array $event, array $current): void
    {
        if ($current['status'] !== 'confirmed' || $current['reference_id'] !== $event['reference_id']) {
            $this->conflict('La referencia indicada no corresponde al fondeo confirmado del crédito.');
        }
        if (CarbonImmutable::parse($event['confirmed_at'])->timestamp > now()->timestamp + 30) {
            $this->access->fail(422, 'FUNDING_INVALID', 'La fecha de confirmación del bloqueo es posterior a la actual.');
        }
        $credit->update([
            'funding_hold' => $event,
            'status' => 'requires_review',
            'observations' => [...$credit->observations, [
                'code' => 'FUNDING_UNDER_RECONCILIATION', 'message' => 'El alta está bloqueada mientras Cajas concilia el fondeo.',
            ]],
        ]);
    }

    private function cancel(CajasV1PendingCredit $credit, array $event, array $current): array
    {
        $this->validateReconciliation($credit, $event, $current);
        $history = $this->reconciliationHistory($credit, $event);
        $this->reverseOriginalDisbursement($credit, $event, $current['amount_cents']);
        $credit->update([
            'funding_cancelled_version' => $current['version'] + 1,
            'status' => 'requires_review',
            'observations' => [...$this->scheduleObservations($credit), [
                'code' => 'FUNDING_CANCELLED', 'message' => 'El alta requiere un nuevo desembolso confirmado.',
            ]],
        ]);

        return $history;
    }

    private function replace(CajasV1PendingCredit $credit, array $event, array $current): array
    {
        $this->validateReconciliation($credit, $event, $current);
        $this->validateNewReference($event['disbursement_id']);
        $this->validateDisbursement($credit, $event);
        $observations = $this->credits->currentScheduleObservations($credit);
        $history = $this->reconciliationHistory($credit, $event);

        $this->reverseOriginalDisbursement($credit, $event, $current['amount_cents']);
        $this->recordDisbursement($credit, $event);
        $credit->update([
            'disbursement_id' => $event['disbursement_id'],
            'disbursement' => $event,
            'funding_hold' => null,
            'observations' => $observations,
            'status' => $observations === [] ? 'pending' : 'requires_review',
        ]);

        return $history;
    }

    private function validateReconciliation(CajasV1PendingCredit $credit, array $event, array $current): void
    {
        if ($current['type'] !== 'disbursement' || $current['status'] !== 'under_reconciliation'
            || $current['reference_id'] !== $event['reference_id']
            || DB::table('cajas_v1_funding_events')->where('reconciliation_id', $event['reconciliation_id'])->exists()) {
            $this->conflict('El desembolso bloqueado o la referencia de conciliación no permiten aplicar el ajuste.');
        }
        $confirmedAt = CarbonImmutable::parse($event['confirmed_at']);
        if ($confirmedAt->isBefore(CarbonImmutable::parse($credit->funding_hold['confirmed_at']))
            || $confirmedAt->timestamp > now()->timestamp + 30) {
            $this->access->fail(422, 'FUNDING_INVALID', 'La fecha de conciliación debe corresponder al bloqueo vigente.');
        }
    }

    private function reverseOriginalDisbursement(CajasV1PendingCredit $credit, array $event, int $amount): void
    {
        // Funding-Proof attests that Cajas posted the effective reconciliation for this disbursement.
        if ($credit->disbursement['recipient']['type'] === 'operator') {
            $this->ledger->reverseDisbursement(
                $credit->operator, $this->auditActor(), ['cut_id' => $credit->cut_id, 'version' => $credit->cut_version],
                $event['reference_id'], $event['reconciliation_id'], $amount, $credit->public_id,
            );
        }
    }

    private function reconciliationHistory(CajasV1PendingCredit $credit, array $event): array
    {
        return [
            'reconciliation_id' => $event['reconciliation_id'],
            'reconciled_reference_id' => $event['reference_id'],
            'reconciliation_history' => json_encode([
                'disbursement' => $credit->disbursement, 'hold' => $credit->funding_hold, $event['action'] => $event,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function confirm(CajasV1PendingCredit $credit, array $event, array $current): void
    {
        if ($current['status'] !== 'unfunded') {
            $this->conflict('El estado vigente no permite confirmar un desembolso.');
        }
        $this->validateNewReference($event['disbursement_id']);
        $this->validateDisbursement($credit, $event);
        $this->recordDisbursement($credit, $event);
        $changes = ['disbursement_id' => $event['disbursement_id'], 'disbursement' => $event];
        if ($credit->funding_cancelled_version !== null) {
            $observations = $this->scheduleObservations($credit);
            $changes += [
                'funding_hold' => null, 'funding_cancelled_version' => null,
                'observations' => $observations, 'status' => $observations === [] ? 'pending' : 'requires_review',
            ];
        }
        $credit->update($changes);
    }

    private function validateNewReference(string $reference): void
    {
        if (CajasV1PendingCredit::where('disbursement_id', $reference)->exists()
            || DB::table('cajas_v1_funding_events')->where('reconciled_reference_id', $reference)->exists()) {
            $this->conflict('La referencia del nuevo desembolso ya está vinculada al historial de fondeo.');
        }
    }

    private function recordDisbursement(CajasV1PendingCredit $credit, array $event): void
    {
        if ($event['recipient']['type'] === 'operator') {
            $this->ledger->record(
                $credit->operator, $this->auditActor(), ['cut_id' => $credit->cut_id, 'version' => $credit->cut_version],
                'cajas_v1_disbursement', $event['disbursement_id'], $event['amount_cents'], $credit->public_id,
            );
        }
    }

    private function scheduleObservations(CajasV1PendingCredit $credit): array
    {
        return array_values(array_filter($credit->observations, fn (array $item) => ! in_array(
            $item['code'], ['FUNDING_UNDER_RECONCILIATION', 'FUNDING_CANCELLED'], true,
        )));
    }

    private function auditActor(): User
    {
        return User::where('email', $this->access->setting('audit_user_email'))
            ->where('status', 'active')->whereNull('locked_at')->first()
            ?? throw new RuntimeException('Falta configurar el usuario de auditoría del piloto.');
    }

    private function verifyProof(CajasV1PendingCredit $credit, array $body, string $hash, string $proof): void
    {
        $claims = $this->signature->claims($proof);
        $expected = [
            'iss' => 'cajas', 'aud' => 'prestamos', 'environment' => $this->access->proofEnvironment(), 'body_sha256' => $hash,
            'pending_credit_id' => $credit->public_id,
        ];
        foreach (['operator', 'event_id', 'expected_funding_version', 'action', 'confirmation_id', 'confirmed_by'] as $field) {
            $expected[$field] = $body[$field] ?? null;
        }
        foreach ($expected as $field => $value) {
            if ($value === null || ($claims[$field] ?? null) !== $value) {
                $this->access->fail(403, 'FUNDING_PROOF_INVALID', 'La confirmación firmada del fondeo no es válida.');
            }
        }
    }

    private function validatedEvent(array $body): array
    {
        $validator = Validator::make($body, [
            'operator' => ['required', self::ID], 'currency' => 'required|in:MXN',
            'event_id' => ['required', self::ID], 'action' => 'required|in:confirm,hold,cancel,replace',
            'expected_funding_version' => ['required', 'integer', 'between:0,2147483647'],
            'disbursement_id' => ['exclude_unless:action,confirm,replace', 'required', self::ID],
            'cash_account_id' => ['exclude_unless:action,confirm,replace', 'required', self::ID],
            'recipient' => 'exclude_unless:action,confirm,replace|required|array:type,id',
            'recipient.type' => 'exclude_unless:action,confirm,replace|required|in:client,operator',
            'recipient.id' => ['exclude_unless:action,confirm,replace', 'required', self::ID],
            'amount_cents' => 'exclude_unless:action,confirm,replace|required|integer|between:1,999999999999999',
            'disbursed_on' => 'exclude_unless:action,confirm,replace|required|date_format:Y-m-d',
            'reference_id' => ['exclude_if:action,confirm', 'required', self::ID],
            'reason' => 'exclude_if:action,confirm|required|string|max:1000',
            'reconciliation_id' => ['exclude_unless:action,cancel,replace', 'required', self::ID],
            'confirmation_id' => ['required', self::ID],
            'evidence_ref' => 'required|string|max:255', 'confirmed_by' => ['required', self::ID],
            'confirmed_at' => ['required', 'date', 'regex:/Z\z/'],
        ]);
        if ($validator->fails()) {
            if (array_intersect(['confirmation_id', 'evidence_ref', 'confirmed_by', 'confirmed_at'], $validator->errors()->keys()) !== []) {
                $this->access->fail(403, 'FUNDING_PROOF_INVALID', 'Faltan datos válidos para acreditar el desembolso.');
            }
            $this->access->fail(422, 'INVALID_REQUEST', 'Hay campos de fondeo no válidos.', $validator->errors()->toArray());
        }
        if (! is_int($body['expected_funding_version'])
            || (in_array($body['action'], ['confirm', 'replace'], true) && ! is_int($body['amount_cents']))) {
            $this->access->fail(422, 'INVALID_REQUEST', 'El importe y la versión del fondeo deben ser enteros JSON.');
        }

        return $validator->validated();
    }

    private function validateDisbursement(CajasV1PendingCredit $credit, array $event, ?string $cutDate = null, int $errorStatus = 422): void
    {
        $recipientId = $event['recipient']['type'] === 'operator'
            ? $credit->operator->public_id : ($credit->proposal['client_id'] ?? null);
        if ($cutDate === null) {
            $cutBody = DB::table('cajas_v1_cut_executions')->where('cut_id', $credit->cut_id)
                ->where('version', $credit->cut_version)->value('body');
            $cutDate = json_decode($cutBody, true, flags: JSON_THROW_ON_ERROR)['cut_on'];
        }
        if (($event['action'] ?? null) === 'replace') {
            $cutDate = max($cutDate, CarbonImmutable::parse($credit->funding_hold['confirmed_at'])->setTimezone('America/Merida')->toDateString());
        }
        $confirmed = CarbonImmutable::parse($event['confirmed_at'])->setTimezone('America/Merida');
        if ($credit->operator->status !== 'active' || $event['amount_cents'] !== $credit->proposal['terms']['principal_cents']
            || $event['recipient']['id'] !== $recipientId
            || $event['disbursed_on'] < $cutDate
            || $event['disbursed_on'] > $confirmed->toDateString()
            || $confirmed->timestamp > now()->timestamp + 30) {
            $this->access->fail($errorStatus, $errorStatus === 409 ? 'FUNDING_CONFLICT' : 'FUNDING_INVALID',
                'El desembolso no coincide con el capital, el receptor o la fecha del crédito.');
        }
    }

    private function conflict(string $message): never
    {
        $this->access->fail(409, 'FUNDING_CONFLICT', $message);
    }
}
