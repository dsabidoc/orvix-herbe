<?php

namespace App\Domain\Cajas\V1;

use App\Domain\Loans\InstallmentPaymentPolicy;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdinaryPayment
{
    public function __construct(private InstallmentPaymentPolicy $policy) {}

    public function hasPendingPayment(Installment $letter): bool
    {
        return CollectionMovement::where('loan_id', $letter->loan_id)->where('confirmation_status', 'reported')->exists();
    }

    public function eligible(Installment $letter, string $cutOn): bool
    {
        return in_array('ordinary', $this->allowedTypes($letter, $cutOn), true);
    }

    public function allowedTypes(Installment $letter, string $cutOn): array
    {
        $remaining = Money::cents($letter->remaining_amount);
        if (! $this->canCarryPayment($letter) || $remaining <= 0) {
            return [];
        }
        $components = $this->policy->remainingComponentsCents($letter);
        if (array_sum($components) !== $remaining) {
            return [];
        }

        if (! $this->policy->isFutureMonth($letter, $cutOn)) {
            return ['ordinary'];
        }

        return $components['principal'] > 0
            ? ['capital', 'capital_interest'] : ['capital_interest'];
    }

    public function principalCents(Installment $letter): int
    {
        return $this->policy->remainingComponentsCents($letter)['principal'];
    }

    public function pendingTail(Loan $loan): HasMany
    {
        return $loan->installments()->where('remaining_amount', '>', 0)->reorder('number', 'desc');
    }

    /** @return array<int, int> Capital en centavos, por ID interno, desde la última letra. */
    public function advanceableTail(Loan $loan, string $cutOn): array
    {
        $tail = [];
        foreach ($this->pendingTail($loan)->with('allocations.movement')->get() as $letter) {
            $letter->setRelation('loan', $loan);
            if (! in_array('capital', $this->allowedTypes($letter, $cutOn), true)) {
                break;
            }
            $tail[$letter->id] = $this->principalCents($letter);
        }

        return $tail;
    }

    private function canCarryPayment(Installment $letter): bool
    {
        $loan = $letter->loan;

        return $loan->status === 'active' && ! $loan->is_frozen
            && in_array($loan->calculation_method ?? 'regular', ['regular', 'rounded'], true)
            && Money::cents($letter->interest_vat_amount) === 0
            && ! $loan->vat_enabled
            && (($loan->calculation_method ?? 'regular') === 'rounded' || Money::cents($letter->administration_fee_amount) === 0);
    }

    public function version(Installment $letter, bool $pending): string
    {
        $loan = $letter->loan;
        $allocations = $letter->allocations->sortBy('id')->map(fn ($allocation) => [
            $allocation->id, $allocation->amount, $allocation->movement?->type,
            ...($allocation->principal_cents === null ? [] : [$allocation->principal_cents]),
        ])->values()->all();

        return hash('sha256', json_encode([
            $letter->public_id, $letter->due_date->toDateString(), $letter->number, $letter->status,
            $letter->remaining_amount, $letter->applied_amount, $letter->contract_amount,
            $letter->principal_amount, $letter->interest_amount, $letter->administration_fee_amount,
            $letter->interest_vat_amount, $loan->operator_id, $loan->status, $loan->is_frozen,
            $loan->calculation_method, $loan->vat_enabled, $pending, $allocations,
        ], JSON_THROW_ON_ERROR));
    }

    public function allocation(Installment $letter, int $amount, string $type): array
    {
        $components = $this->policy->remainingComponentsCents($letter);
        $capitalOnly = in_array($type, ['capital', 'general_capital'], true);
        $split = $this->policy->splitPaymentCents($components, $amount, $capitalOnly);

        return [
            'installment_id' => $letter->public_id,
            'principal_cents' => $split['principal'],
            'interest_cents' => $split['interest'],
            'interest_waived_cents' => $capitalOnly ? $components['interest'] : 0,
            'remaining_after_cents' => $capitalOnly ? 0 : Money::cents($letter->remaining_amount) - $amount,
        ];
    }

    public function catalogRow(Installment $letter, string $cutOn): array
    {
        $loan = $letter->loan;
        $pending = $this->hasPendingPayment($letter);
        $components = $this->policy->remainingComponentsCents($letter);

        return [
            'loan_id' => $loan->public_id,
            'folio' => $loan->folio,
            'client' => trim($loan->client->first_name.' '.$loan->client->last_name),
            'model' => $loan->vehicle?->model ?? 'Vehículo',
            'calculation_method' => $loan->calculation_method ?? 'regular',
            'day' => $loan->payment_day,
            'installment_id' => $letter->public_id,
            'number' => $letter->number,
            'status' => $pending ? 'reported' : ($letter->status === 'upcoming' ? 'pending' : $letter->status),
            'due_on' => $letter->due_date->toDateString(),
            'remaining_cents' => Money::cents($letter->remaining_amount),
            'principal_cents' => $components['principal'],
            'interest_cents' => $components['interest'],
            'optional_collection_fee_cents' => $loan->calculation_method === 'rounded' ? Money::cents($letter->administration_fee_amount) : 0,
            'allowed_payment_types' => $pending ? [] : $this->allowedTypes($letter, $cutOn),
            'version' => $this->version($letter, $pending),
        ];
    }
}
