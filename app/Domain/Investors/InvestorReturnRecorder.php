<?php

namespace App\Domain\Investors;

use App\Models\CollectionMovement;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Support\Money;

class InvestorReturnRecorder
{
    public function record(Loan $loan, $installment, int $principalCents, int $interestCents, CollectionMovement $movement, int $userId): void
    {
        $loan->loadMissing('investments.investor');
        $capitalCents = Money::cents($loan->capital);

        if ($capitalCents <= 0 || $loan->investments->isEmpty() || ($principalCents + $interestCents) <= 0) {
            return;
        }

        foreach ($loan->investments as $investment) {
            if ($investment->status !== 'active') {
                continue;
            }

            $investor = Investor::query()->whereKey($investment->investor_id)->lockForUpdate()->first();

            if (! $investor) {
                continue;
            }

            $capitalShareRate = Money::cents($investment->amount) / $capitalCents;
            $returnedCapitalCents = (int) round($principalCents * $capitalShareRate);
            $generatedInterestCents = (int) round($interestCents * (float) $investment->investor_share_rate);
            $totalCents = $returnedCapitalCents + $generatedInterestCents;

            if ($totalCents <= 0) {
                continue;
            }

            $investor->forceFill([
                'returned_capital_balance' => Money::decimal(Money::cents($investor->returned_capital_balance) + $returnedCapitalCents),
                'generated_interest_balance' => Money::decimal(Money::cents($investor->generated_interest_balance) + $generatedInterestCents),
            ])->save();

            InvestorCapitalMovement::query()->create([
                'public_id' => (string) str()->ulid(),
                'investor_id' => $investor->id,
                'loan_id' => $loan->id,
                'investment_id' => $investment->id,
                'created_by' => $userId,
                'type' => 'payment_returns_recorded',
                'amount' => Money::decimal($totalCents),
                'balance_before' => $investor->available_capital,
                'balance_after' => $investor->available_capital,
                'notes' => 'Retornos automaticos por pago confirmado '.$movement->folio,
                'metadata' => [
                    'collection_movement_id' => $movement->id,
                    'installment_id' => $installment->id,
                    'installment_number' => $installment->number,
                    'returned_capital' => Money::decimal($returnedCapitalCents),
                    'generated_interest' => Money::decimal($generatedInterestCents),
                    'administration_fee_excluded' => $installment->administration_fee_amount,
                    'interest_vat_excluded' => $installment->interest_vat_amount,
                ],
            ]);
        }
    }
}
