<?php

namespace App\Domain\Loans;

use App\Domain\Investors\InvestorReturnRecorder;
use App\Models\CollectionMovement;
use App\Models\Loan;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanSettlementService
{
    public function __construct(private readonly InvestorReturnRecorder $investorReturnRecorder) {}

    /**
     * @return array{settled_on:string,total_cents:int,rows:array<int,array<string,mixed>>}
     */
    public function quote(Loan $loan, CarbonImmutable|string|null $settledOn = null): array
    {
        $settledOn = $this->settledOn($settledOn);
        $monthStart = $settledOn->startOfMonth();
        $monthEnd = $settledOn->endOfMonth();
        $rows = [];
        $unpaidInstallments = $loan->installments()->where('remaining_amount', '>', 0)->orderBy('number')->get();

        if (($loan->calculation_method ?? 'regular') === 'interest_only') {
            $anchor = $unpaidInstallments->first();
            $capitalCents = Money::cents($anchor?->capital_balance ?? $loan->capital);

            foreach ($unpaidInstallments as $installment) {
                $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();

                if ($dueDate->gt($monthEnd)) {
                    continue;
                }

                $components = $this->remainingComponents($installment);
                $bucket = $dueDate->lt($monthStart) ? 'overdue' : 'current_month';

                $rows[] = [
                    'installment_id' => $installment->id,
                    'number' => (int) $installment->number,
                    'due_date' => $dueDate->toDateString(),
                    'bucket' => $bucket,
                    'amount_cents' => $components['interest_cents'],
                    'principal_cents' => 0,
                    'interest_cents' => $components['interest_cents'],
                ];
            }

            if ($anchor && $capitalCents > 0) {
                $rows[] = [
                    'installment_id' => $anchor->id,
                    'number' => (int) $anchor->number,
                    'due_date' => CarbonImmutable::parse($anchor->due_date, 'America/Merida')->toDateString(),
                    'bucket' => 'capital_return',
                    'amount_cents' => $capitalCents,
                    'principal_cents' => $capitalCents,
                    'interest_cents' => 0,
                ];
            }

            return [
                'settled_on' => $settledOn->toDateString(),
                'total_cents' => array_sum(array_column($rows, 'amount_cents')),
                'rows' => $rows,
            ];
        }

        foreach ($unpaidInstallments as $installment) {
            $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();
            $components = $this->remainingComponents($installment);
            $bucket = 'future';
            $amountCents = $components['principal_cents'];
            $principalCents = $components['principal_cents'];
            $interestCents = 0;

            if ($dueDate->lt($monthStart)) {
                $bucket = 'overdue';
                $amountCents = $components['remaining_cents'];
                $interestCents = $components['interest_cents'];
            } elseif ($dueDate->betweenIncluded($monthStart, $monthEnd)) {
                $bucket = 'current_month';
                $interestCents = $components['interest_cents'];
                $amountCents = min(
                    $components['remaining_cents'],
                    $components['principal_cents'] + $components['interest_cents'],
                );
            }

            $rows[] = [
                'installment_id' => $installment->id,
                'number' => (int) $installment->number,
                'due_date' => $dueDate->toDateString(),
                'bucket' => $bucket,
                'amount_cents' => $amountCents,
                'principal_cents' => $principalCents,
                'interest_cents' => $interestCents,
            ];
        }

        return [
            'settled_on' => $settledOn->toDateString(),
            'total_cents' => array_sum(array_column($rows, 'amount_cents')),
            'rows' => $rows,
        ];
    }

    public function settle(Loan $loan, string $reason, int $userId, CarbonImmutable|string|null $settledOn = null): Loan
    {
        return DB::transaction(function () use ($loan, $reason, $userId, $settledOn) {
            $loan = Loan::query()
                ->with(['investments'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();
            $quote = $this->quote($loan, $settledOn);
            $settledOn = CarbonImmutable::parse($quote['settled_on'], 'America/Merida');
            $movement = null;

            if ($quote['total_cents'] > 0) {
                $movement = CollectionMovement::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'folio' => 'MOV-'.now('America/Merida')->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                    'idempotency_key' => sha1('loan-settlement|'.$loan->id.'|'.$userId.'|'.$quote['settled_on'].'|'.now('America/Merida')->timestamp),
                    'loan_id' => $loan->id,
                    'operator_id' => $loan->operator_id,
                    'registered_by' => $userId,
                    'confirmed_by' => $userId,
                    'operated_on' => $quote['settled_on'],
                    'confirmed_at' => now('America/Merida'),
                    'contract_amount' => Money::decimal($quote['total_cents']),
                    'operator_surcharge_amount' => '0.00',
                    'external_concepts_amount' => '0.00',
                    'type' => 'settlement',
                    'payment_method' => 'cash',
                    'notes' => 'Liquidacion: capital futuro, interes del mes corriente y sin intereses futuros.',
                    'confirmation_status' => 'applied',
                ]);
            }

            foreach ($quote['rows'] as $row) {
                $installment = $loan->installments()->whereKey($row['installment_id'])->lockForUpdate()->firstOrFail();
                $newAppliedCents = Money::cents($installment->applied_amount) + $row['amount_cents'];

                $installment->update([
                    'applied_amount' => Money::decimal($newAppliedCents),
                    'remaining_amount' => '0.00',
                    'status' => 'settled',
                ]);

                if ($movement && $row['amount_cents'] > 0) {
                    PaymentAllocation::query()->create([
                        'collection_movement_id' => $movement->id,
                        'installment_id' => $installment->id,
                        'amount' => Money::decimal($row['amount_cents']),
                    ]);

                    $this->investorReturnRecorder->record(
                        $loan,
                        $installment,
                        $row['principal_cents'],
                        $row['interest_cents'],
                        $movement,
                        $userId,
                    );
                }
            }

            $loan->update([
                'status' => 'settled',
                'settlement_reason' => $reason,
                'settled_at' => $settledOn->endOfDay(),
                'settled_by' => $userId,
            ]);

            return $loan->fresh(['client', 'installments', 'movements']);
        });
    }

    /**
     * @return array{remaining_cents:int,principal_cents:int,administration_fee_cents:int,interest_cents:int,interest_vat_cents:int}
     */
    private function remainingComponents($installment): array
    {
        $contractCents = $this->operationalCents($installment);
        $remainingCents = Money::cents($installment->remaining_amount);
        $ratio = $contractCents > 0 ? min(1, $remainingCents / $contractCents) : 0;

        return [
            'remaining_cents' => $remainingCents,
            'principal_cents' => (int) round(Money::cents($installment->principal_amount) * $ratio),
            'administration_fee_cents' => (int) round(Money::cents($installment->administration_fee_amount) * $ratio),
            'interest_cents' => (int) round(Money::cents($installment->interest_amount) * $ratio),
            'interest_vat_cents' => (int) round(Money::cents($installment->interest_vat_amount) * $ratio),
        ];
    }

    private function settledOn(CarbonImmutable|string|null $settledOn): CarbonImmutable
    {
        if ($settledOn instanceof CarbonImmutable) {
            return $settledOn->startOfDay();
        }

        return CarbonImmutable::parse($settledOn ?: now('America/Merida')->toDateString(), 'America/Merida')->startOfDay();
    }

    private function operationalCents($installment): int
    {
        $operationalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

        return $operationalCents > 0 ? $operationalCents : Money::cents($installment->contract_amount);
    }
}
