<?php

namespace App\Domain\Cajas\V1;

use App\Domain\Investors\ReturnProvenance;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentCorrection
{
    public function __construct(
        private IntegrationAccess $access,
        private CutApproval $approval,
        private PaymentApplicationService $payments,
        private ReturnProvenance $provenance,
    ) {}

    public function apply(string $cutId, array $correction, string $body, string $proof): JsonResponse
    {
        $hash = hash('sha256', $body);
        try {
            $this->approval->verifyCorrection($cutId, $correction, $hash, $proof);
        } catch (HttpResponseException $exception) {
            // Another request may have finished while Cajas was checking the approval.
            return $this->replay($cutId, $correction['correction_id'], $hash) ?? throw $exception;
        }

        return DB::transaction(function () use ($cutId, $correction, $body, $hash): JsonResponse {
            // Take the same SQLite writer lock as the other financial paths before reading the state.
            DB::table('operators')->where('public_id', $correction['operator'])->update(['id' => DB::raw('id')]);
            if (($result = $this->replay($cutId, $correction['correction_id'], $hash)) !== null) {
                return $result;
            }
            try {
                $result = DB::transaction(fn () => $this->applyFinancial($cutId, $correction, $body, $hash));
            } catch (HttpResponseException $exception) {
                $response = $exception->getResponse();
                $result = $response instanceof JsonResponse ? $response->getData(true) : [];
                if ($response->getStatusCode() !== 409 || ! in_array(
                    $result['error']['code'] ?? null, ['CORRECTION_BLOCKED', 'PAYMENT_CHANGED'], true,
                )) {
                    throw $exception;
                }
                DB::table('cajas_v1_correction_rejections')->insert([
                    'correction_id' => $correction['correction_id'], 'cut_id' => $cutId, 'operator' => $correction['operator'],
                    'body_hash' => $hash, 'body' => $body, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $response;
            }

            return response()->json($result);
        }, 5);
    }

    private function applyFinancial(string $cutId, array $correction, string $body, string $hash): array
    {
        $execution = DB::table('cajas_v1_cut_executions')->where('cut_id', $cutId)
            ->where('version', $correction['cut_version'])->where('operator', $correction['operator'])->first();
        if (! $execution || $execution->status !== 'applied') {
            $this->paymentChanged();
        }
        $movement = $this->originalMovement($execution, $correction);
        $installment = $movement->allocations->sole()->installment;
        $this->assertCanReverse($movement, $installment);
        $this->assertOriginalReturnsAvailable($movement);

        $auditUser = User::where('email', $this->access->setting('audit_user_email'))
            ->where('status', 'active')->whereNull('locked_at')->first()
            ?? throw new RuntimeException('Falta configurar el usuario de auditoría del piloto.');
        $originalAllocation = $movement->allocations->sole()->getAttributes();
        $remaining = Money::cents($installment->remaining_amount);
        $this->payments->reverseCajasOrdinary($movement, $auditUser->id);

        $publicId = (string) Str::ulid();
        $replacement = CollectionMovement::create([
            'public_id' => $publicId, 'folio' => 'CAJAS-'.$publicId,
            'idempotency_key' => 'cajas-cut-item-correction-'.hash('sha256', $correction['correction_id']),
            'loan_id' => $movement->loan_id, 'target_installment_id' => $movement->target_installment_id,
            'operator_id' => $movement->operator_id, 'registered_by' => $auditUser->id,
            'reversed_movement_id' => $movement->id,
            'operated_on' => $movement->operated_on, 'registered_at' => now('America/Merida'),
            'contract_amount' => Money::decimal($correction['after_cents']), 'type' => 'ordinary',
            'payment_method' => $movement->payment_method, 'affects_investors' => $movement->affects_investors,
            'reference' => $correction['approval']['id'], 'notes' => $correction['reason'],
            'confirmation_status' => 'reported',
        ]);
        $replacement = $this->payments->confirm($replacement, $auditUser->id);
        $allocation = $replacement->allocations->first();
        $expected = $remaining + $correction['before_cents'] - $correction['after_cents'];
        if ($replacement->allocations->count() !== 1 || $allocation->installment_id !== $installment->id
            || Money::cents($allocation->amount) !== $correction['after_cents']
            || Money::cents($allocation->installment->remaining_amount) !== $expected) {
            $this->blocked($movement, 'REPLACEMENT_NOT_APPLICABLE');
        }
        $result = [
            'correction_id' => $correction['correction_id'], 'status' => 'applied', 'currency' => 'MXN',
            'reversed_payment_id' => $movement->public_id, 'reversed_payment_folio' => $movement->folio,
            'replacement_payment_id' => $replacement->public_id, 'replacement_payment_folio' => $replacement->folio,
            'contract_amount_cents' => $correction['after_cents'], 'installment_remaining_cents' => $expected,
        ];
        DB::table('cajas_v1_payment_corrections')->insert([
            'correction_id' => $correction['correction_id'], 'cut_execution_id' => $execution->id,
            'operator' => $correction['operator'], 'original_movement_id' => $movement->id,
            'replacement_movement_id' => $replacement->id, 'body_hash' => $hash, 'body' => $body,
            'original_allocation' => json_encode($originalAllocation, JSON_THROW_ON_ERROR),
            'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $result;
    }

    public function replay(string $cutId, string $correctionId, string $hash): ?JsonResponse
    {
        $existing = DB::table('cajas_v1_payment_corrections as correction')
            ->join('cajas_v1_cut_executions as cut', 'cut.id', '=', 'correction.cut_execution_id')
            ->where('correction.correction_id', $correctionId)
            ->first(['correction.operator', 'correction.body_hash', 'correction.result', 'cut.cut_id']);
        $status = 200;
        if (! $existing) {
            $existing = DB::table('cajas_v1_correction_rejections')->where('correction_id', $correctionId)->first();
            $status = 409;
        }
        if (! $existing) {
            return null;
        }
        $this->access->authorizeOperator($existing->operator);
        if ($existing->cut_id !== $cutId || ! hash_equals($existing->body_hash, $hash)) {
            $this->access->fail(409, 'IDEMPOTENCY_KEY_REUSED', 'La corrección ya está registrada con otro corte o contenido.');
        }

        return response()->json(json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR), $status);
    }

    private function originalMovement(object $execution, array $correction): CollectionMovement
    {
        $cut = json_decode($execution->body, true, flags: JSON_THROW_ON_ERROR);
        $item = collect($cut['items'])->firstWhere('item_id', $correction['item_id']);
        $result = json_decode($execution->result, true, flags: JSON_THROW_ON_ERROR);
        $payment = collect($result['payments'])->firstWhere('item_id', $correction['item_id']);
        if (($item['payment_type'] ?? null) !== 'ordinary'
            || ($payment['payment_id'] ?? null) !== $correction['payment_id']
            || $payment['payment_folio'] !== $correction['payment_folio']) {
            $this->paymentChanged();
        }
        $movement = CollectionMovement::with(['allocations.installment', 'operator', 'weeklyCut'])
            ->where('public_id', $correction['payment_id'])->lockForUpdate()->first();
        if (! $movement || $movement->confirmation_status !== 'applied' || $movement->type !== 'ordinary'
            || $movement->operator?->public_id !== $correction['operator'] || $movement->folio !== $correction['payment_folio']
            || $movement->reversed_movement_id !== null || $movement->totalCents() !== $correction['before_cents']
            || Money::cents($movement->contract_amount) !== $correction['before_cents']
            || $movement->allocations->count() !== 1) {
            $this->paymentChanged();
        }
        $allocation = $movement->allocations->sole();
        if ($allocation->installment_id !== $movement->target_installment_id || $allocation->installment->public_id !== $item['installment_id']
            || Money::cents($allocation->amount) !== $correction['before_cents']) {
            $this->paymentChanged();
        }

        return $movement;
    }

    private function assertCanReverse(CollectionMovement $movement, Installment $installment): void
    {
        $loan = Loan::whereKey($movement->loan_id)->lockForUpdate()->firstOrFail();
        if ($loan->operator_id !== $movement->operator_id || $movement->operator?->status !== 'active'
            || ($loan->status !== 'active' && ! ($loan->status === 'settled' && $loan->settlement_reason === 'calendario_pagado'))
            || DB::table('settlements')->where('loan_id', $loan->id)->where('status', 'applied')->exists()) {
            $this->blocked($movement, 'LOAN_STATE_CHANGED');
        }
        if ($movement->weeklyCut?->status === 'closed'
            || DB::table('weekly_cuts')->where('id', $movement->origin_weekly_cut_id)->where('status', 'closed')->exists()) {
            $this->blocked($movement, 'CUT_CLOSED');
        }
        if (CollectionMovement::where('loan_id', $loan->id)->whereKeyNot($movement->id)
            ->where(fn ($query) => $query->where('confirmation_status', 'reported')->orWhere(fn ($later) => $later
                ->where('confirmation_status', 'applied')
                ->where(fn ($order) => $order->where('id', '>', $movement->id)->orWhere('confirmed_at', '>', $movement->confirmed_at))))
            ->exists() || Money::cents($installment->applied_amount) < Money::cents($movement->contract_amount)) {
            $this->blocked($movement, 'LATER_PAYMENTS_OR_BALANCE_CHANGE');
        }
    }

    private function assertOriginalReturnsAvailable(CollectionMovement $movement): void
    {
        $origin = InvestorCapitalMovement::where('type', 'payment_returns_recorded')
            ->where('metadata->collection_movement_id', $movement->id)->oldest('id')->get();
        $investments = $movement->loan->investments()->lockForUpdate()->get()->keyBy('id');
        if (($movement->affects_investors && $origin->pluck('investment_id')->sort()->values()->all() !== $investments->keys()->sort()->values()->all())
            || (! $movement->affects_investors && $origin->isNotEmpty())) {
            $this->blocked($movement, 'RETURN_PROVENANCE_UNVERIFIABLE');
        }
        foreach ($origin->groupBy('investor_id') as $investorId => $ret) {
            $investor = $ret->first()->investor()->lockForUpdate()->firstOrFail();
            $state = $this->provenance->state($investor);
            $uncertain = false;
            $used = false;
            foreach ($ret as $entry) {
                $investment = $investments[$entry->investment_id];
                if ($entry->loan_id !== $movement->loan_id || $entry->investor_id !== $investment->investor_id
                    || $investment->status !== 'active' || $investment->updated_at->gt($movement->confirmed_at)
                    || ($entry->metadata['installment_id'] ?? null) !== $movement->target_installment_id
                    || Money::cents($entry->amount) !== Money::cents($entry->metadata['returned_capital'] ?? 0)
                        + Money::cents($entry->metadata['generated_interest'] ?? 0)) {
                    $this->blocked($movement, 'RETURN_PROVENANCE_UNVERIFIABLE');
                }
                foreach (array_keys(ReturnProvenance::COMPONENTS) as $component) {
                    $amount = Money::cents($entry->metadata[$component] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }
                    $source = $state[$component][$entry->id] ?? null;
                    $used = $used || ($source['used'] ?? 0) > 0;
                    $uncertain = $uncertain || $source === null || $source['uncertain'] || $source['remaining'] !== $amount;
                }
            }
            if ($used) {
                $this->blocked($movement, 'RETURNS_ALREADY_USED');
            }
            foreach ($state as $component => $sources) {
                $verified = array_filter($sources, fn (array $source) => ! $source['uncertain']);
                $uncertain = $uncertain || array_sum(array_column($verified, 'remaining')) > $this->provenance->balance($investor, $component);
            }
            if ($investor->id !== (int) $investorId || $uncertain) {
                $this->blocked($movement, 'RETURN_PROVENANCE_UNVERIFIABLE');
            }
        }
    }

    private function paymentChanged(): never
    {
        $this->access->fail(409, 'PAYMENT_CHANGED', 'El pago original no coincide con el movimiento activo de ese concepto.');
    }

    private function blocked(CollectionMovement $movement, string $reason): never
    {
        $this->access->fail(409, 'CORRECTION_BLOCKED', 'El pago no se puede revertir con el estado financiero vigente.', [
            ['payment_id' => $movement->public_id, 'reason' => $reason],
        ]);
    }
}
