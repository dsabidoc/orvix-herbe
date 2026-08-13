<?php

namespace App\Domain\Loans;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Models\WeeklyCut;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LoanDeletionService
{
    public function __construct(private readonly WeeklyCutPeriodService $cutPeriodService) {}

    public function delete(Loan $loan, int $userId): void
    {
        DB::transaction(function () use ($loan, $userId) {
            $loan = Loan::query()
                ->with(['investments', 'movements.allocations', 'fundDisbursements'])
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $affectedCutIds = $loan->movements
                ->pluck('weekly_cut_id')
                ->merge($loan->movements->pluck('origin_weekly_cut_id'))
                ->merge($loan->fundDisbursements->pluck('weekly_cut_id'))
                ->filter()
                ->unique()
                ->values();

            $this->reversePaymentReturns($loan, $userId);
            $this->releaseInvestedCapital($loan, $userId);
            $this->deleteDependentRecords($loan);

            AuditEvent::query()->create([
                'user_id' => $userId,
                'action' => 'loan.deleted',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'before' => [
                    'folio' => $loan->folio,
                    'client_id' => $loan->client_id,
                    'operator_id' => $loan->operator_id,
                    'capital' => $loan->capital,
                    'contract_total' => $loan->contract_total,
                    'status' => $loan->status,
                ],
                'reason' => 'Prestamo eliminado por administracion',
            ]);

            $loan->delete();

            WeeklyCut::query()
                ->whereIn('id', $affectedCutIds->all())
                ->get()
                ->each(fn (WeeklyCut $cut) => $this->cutPeriodService->refreshTotals($cut));
        });
    }

    private function reversePaymentReturns(Loan $loan, int $userId): void
    {
        $alreadyReversedIds = InvestorCapitalMovement::query()
            ->where('type', 'payment_returns_reversed')
            ->where('loan_id', $loan->id)
            ->get()
            ->pluck('metadata.reversed_return_movement_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $returnMovements = InvestorCapitalMovement::query()
            ->where('type', 'payment_returns_recorded')
            ->where('loan_id', $loan->id)
            ->when($alreadyReversedIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyReversedIds))
            ->orderByDesc('id')
            ->get();

        foreach ($returnMovements as $returnMovement) {
            $investor = Investor::query()->whereKey($returnMovement->investor_id)->lockForUpdate()->first();

            if (! $investor) {
                continue;
            }

            $returnedCapitalCents = Money::cents($returnMovement->metadata['returned_capital'] ?? 0);
            $generatedInterestCents = Money::cents($returnMovement->metadata['generated_interest'] ?? 0);

            if (
                Money::cents($investor->returned_capital_balance) < $returnedCapitalCents
                || Money::cents($investor->generated_interest_balance) < $generatedInterestCents
            ) {
                throw new RuntimeException('No se puede eliminar: hay retornos de inversionista de este prestamo que ya fueron reinvertidos, retirados o usados.');
            }

            $investor->forceFill([
                'returned_capital_balance' => Money::decimal(Money::cents($investor->returned_capital_balance) - $returnedCapitalCents),
                'generated_interest_balance' => Money::decimal(Money::cents($investor->generated_interest_balance) - $generatedInterestCents),
            ])->save();

            InvestorCapitalMovement::query()->create([
                'public_id' => (string) Str::ulid(),
                'investor_id' => $investor->id,
                'loan_id' => $returnMovement->loan_id,
                'investment_id' => $returnMovement->investment_id,
                'created_by' => $userId,
                'type' => 'payment_returns_reversed',
                'amount' => Money::decimal(Money::cents($returnMovement->amount)),
                'balance_before' => $investor->available_capital,
                'balance_after' => $investor->available_capital,
                'notes' => 'Retornos revertidos por eliminacion de prestamo '.$loan->folio,
                'metadata' => [
                    'collection_movement_id' => $returnMovement->metadata['collection_movement_id'] ?? null,
                    'reversed_return_movement_id' => $returnMovement->id,
                    'returned_capital' => Money::decimal($returnedCapitalCents),
                    'generated_interest' => Money::decimal($generatedInterestCents),
                ],
            ]);
        }
    }

    private function releaseInvestedCapital(Loan $loan, int $userId): void
    {
        foreach ($loan->investments as $investment) {
            $investor = Investor::query()->whereKey($investment->investor_id)->lockForUpdate()->first();

            if (! $investor) {
                continue;
            }

            $amountCents = Money::cents($investment->amount);

            if ($amountCents <= 0) {
                continue;
            }

            $beforeCents = Money::cents($investor->available_capital ?? 0);
            $afterCents = $beforeCents + $amountCents;

            $investor->forceFill(['available_capital' => Money::decimal($afterCents)])->save();

            InvestorCapitalMovement::query()->create([
                'public_id' => (string) Str::ulid(),
                'investor_id' => $investor->id,
                'loan_id' => $loan->id,
                'investment_id' => $investment->id,
                'created_by' => $userId,
                'type' => 'loan_deleted_capital_released',
                'amount' => Money::decimal($amountCents),
                'balance_before' => Money::decimal($beforeCents),
                'balance_after' => Money::decimal($afterCents),
                'notes' => 'Capital liberado por eliminacion de prestamo '.$loan->folio,
            ]);
        }
    }

    private function deleteDependentRecords(Loan $loan): void
    {
        $movementIds = $loan->movements()->pluck('id');
        $installmentIds = $loan->installments()->pluck('id');
        $promissoryNoteIds = DB::table('promissory_notes')->where('loan_id', $loan->id)->pluck('id');
        $settlementQuoteIds = DB::table('settlement_quotes')->where('loan_id', $loan->id)->pluck('id');

        DB::table('weekly_cut_items')->whereIn('collection_movement_id', $movementIds)->delete();
        DB::table('payment_allocations')->whereIn('collection_movement_id', $movementIds)->delete();
        DB::table('payment_allocations')->whereIn('installment_id', $installmentIds)->delete();
        DB::table('collection_movements')->whereIn('reversed_movement_id', $movementIds)->update(['reversed_movement_id' => null]);
        DB::table('collection_movements')->where('loan_id', $loan->id)->delete();

        DB::table('settlements')
            ->where('loan_id', $loan->id)
            ->orWhereIn('settlement_quote_id', $settlementQuoteIds)
            ->delete();
        DB::table('settlement_quotes')->where('loan_id', $loan->id)->delete();

        DB::table('custody_events')->whereIn('promissory_note_id', $promissoryNoteIds)->delete();
        DB::table('promissory_notes')->where('loan_id', $loan->id)->delete();

        DB::table('fund_disbursements')->where('loan_id', $loan->id)->delete();
        DB::table('loan_invoice_movements')->where('loan_id', $loan->id)->delete();
        DB::table('loan_notes')->where('loan_id', $loan->id)->delete();
        DB::table('documents')->where('loan_id', $loan->id)->update(['loan_id' => null]);
        DB::table('installments')->where('loan_id', $loan->id)->delete();
        DB::table('loan_terms_versions')->where('loan_id', $loan->id)->delete();
        DB::table('investment_ledger_entries')
            ->whereIn('investment_id', $loan->investments()->pluck('id'))
            ->delete();
        DB::table('investments')->where('loan_id', $loan->id)->delete();
        DB::table('loan_applications')->where('loan_id', $loan->id)->update(['loan_id' => null]);
    }
}
