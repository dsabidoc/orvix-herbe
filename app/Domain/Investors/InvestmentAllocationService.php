<?php

namespace App\Domain\Investors;

use App\Models\Investment;
use App\Models\CollectionMovement;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestmentAllocationService
{
    public function __construct(private readonly InvestorReturnRecorder $investorReturnRecorder) {}

    /**
     * @param  array<int, array<string, mixed>>  $input
     * @return Collection<int, array{investor:Investor, investor_id:int, capital_cents:int, interest_share_percent:float}>
     */
    public function participants(array $input, int $capitalCents, ?Loan $loan = null, bool $allowEmpty = false): Collection
    {
        $participants = collect($input)
            ->filter(fn (array $row) => filled($row['investor_id'] ?? null))
            ->map(function (array $row) {
                $investor = Investor::query()->whereKey($row['investor_id'])->first();

                return [
                    'investor' => $investor,
                    'investor_id' => (int) ($row['investor_id'] ?? 0),
                    'capital_cents' => Money::cents($row['capital_amount'] ?? 0),
                    'interest_share_percent' => (float) ($row['interest_share_percent'] ?? 0),
                ];
            })
            ->values();

        $this->validateParticipants($participants, $capitalCents, $loan, $allowEmpty);

        return $participants;
    }

    /**
     * @param  Collection<int, array{investor:Investor|null, investor_id:int, capital_cents:int, interest_share_percent:float}>  $participants
     */
    public function validateParticipants(Collection $participants, int $capitalCents, ?Loan $loan = null, bool $allowEmpty = false): void
    {
        if ($participants->isEmpty()) {
            if ($allowEmpty) {
                return;
            }

            throw ValidationException::withMessages([
                'investors' => 'Selecciona al menos un inversionista para asignar el prestamo.',
            ]);
        }

        if ($participants->pluck('investor_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'investors' => 'No puedes repetir el mismo inversionista en la distribucion.',
            ]);
        }

        if ($participants->contains(fn (array $row) => ! $row['investor'] || $row['investor']->status !== 'active')) {
            throw ValidationException::withMessages([
                'investors' => 'Todos los inversionistas deben estar activos.',
            ]);
        }

        if ($participants->contains(fn (array $row) => $row['capital_cents'] < 0)) {
            throw ValidationException::withMessages([
                'investors' => 'El capital aportado por inversionista no puede ser negativo.',
            ]);
        }

        if ($participants->sum('capital_cents') !== $capitalCents) {
            throw ValidationException::withMessages([
                'investors' => 'La suma de capital de inversionistas debe cubrir exactamente el capital del prestamo.',
            ]);
        }

        $interestShare = round($participants->sum('interest_share_percent'), 4);

        if ($interestShare !== 100.0) {
            throw ValidationException::withMessages([
                'investors' => 'La suma de porcentajes de interes debe ser exactamente 100%.',
            ]);
        }

        // Temporalmente se permite asignar capital aunque el inversionista no tenga saldo disponible,
        // para facilitar la carga inicial de prestamos existentes.
    }

    /**
     * @param  array<int, array<string, mixed>>  $input
     */
    public function assignFromInput(Loan $loan, array $input, ?int $userId = null): void
    {
        $participants = $this->participants($input, Money::cents($loan->capital), $loan);

        DB::transaction(function () use ($loan, $participants, $userId) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $this->reverseExistingPaymentReturns($loan, $userId);

            $loan->investments()->with('investor')->get()->each(function (Investment $investment) use ($userId) {
                $investor = Investor::query()->whereKey($investment->investor_id)->lockForUpdate()->firstOrFail();
                $this->creditAvailable($investor, Money::cents($investment->amount), 'loan_investment_released', $userId, $investment->loan_id, $investment->id);
                $investment->delete();
            });

            foreach ($participants as $participant) {
                $investor = Investor::query()->whereKey($participant['investor_id'])->lockForUpdate()->firstOrFail();
                $this->debitAvailable($investor, $participant['capital_cents'], 'loan_funded', $userId, $loan->id);

                $investment = $loan->investments()->create([
                    'public_id' => (string) Str::ulid(),
                    'investor_id' => $investor->id,
                    'vehicle_id' => $loan->vehicle_id,
                    'amount' => Money::decimal($participant['capital_cents']),
                    'investor_share_rate' => number_format($participant['interest_share_percent'] / 100, 6, '.', ''),
                    'administrator_share_rate' => number_format(1 - ($participant['interest_share_percent'] / 100), 6, '.', ''),
                    'starts_on' => $loan->start_date,
                    'status' => 'active',
                    'agreement_snapshot' => [
                        'role' => 'inversionista',
                        'capital_percent' => round(($participant['capital_cents'] / Money::cents($loan->capital)) * 100, 4),
                        'interest_share_percent' => $participant['interest_share_percent'],
                    ],
                ]);

                InvestorCapitalMovement::query()
                    ->where('investor_id', $investor->id)
                    ->where('loan_id', $loan->id)
                    ->whereNull('investment_id')
                    ->where('type', 'loan_funded')
                    ->latest()
                    ->first()
                    ?->update(['investment_id' => $investment->id]);
            }

            $this->replayAppliedPaymentReturns($loan, $userId);
        });
    }

    public function hasParticipants(array $input): bool
    {
        return collect($input)->contains(fn (array $row) => filled($row['investor_id'] ?? null));
    }

    public function availableCentsForInvestor(Investor $investor, ?Loan $loan = null): int
    {
        $availableCents = Money::cents($investor->available_capital ?? 0);

        if ($loan) {
            $availableCents += $loan->investments()
                ->where('investor_id', $investor->id)
                ->sum('amount') * 100;
        }

        return (int) round($availableCents);
    }

    public function creditAvailable(Investor $investor, int $amountCents, string $type, ?int $userId = null, ?int $loanId = null, ?int $investmentId = null, ?string $notes = null): void
    {
        if ($amountCents <= 0) {
            return;
        }

        $beforeCents = Money::cents($investor->available_capital ?? 0);
        $afterCents = $beforeCents + $amountCents;

        $investor->forceFill(['available_capital' => Money::decimal($afterCents)])->save();
        $this->recordMovement($investor, $amountCents, $beforeCents, $afterCents, $type, $userId, $loanId, $investmentId, $notes);
    }

    public function debitAvailable(Investor $investor, int $amountCents, string $type, ?int $userId = null, ?int $loanId = null, ?int $investmentId = null, ?string $notes = null): void
    {
        if ($amountCents <= 0) {
            return;
        }

        $beforeCents = Money::cents($investor->available_capital ?? 0);
        $afterCents = $beforeCents - $amountCents;

        $investor->forceFill(['available_capital' => Money::decimal($afterCents)])->save();
        $this->recordMovement($investor, -$amountCents, $beforeCents, $afterCents, $type, $userId, $loanId, $investmentId, $notes);
    }

    private function recordMovement(Investor $investor, int $amountCents, int $beforeCents, int $afterCents, string $type, ?int $userId, ?int $loanId, ?int $investmentId, ?string $notes): void
    {
        InvestorCapitalMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investor->id,
            'loan_id' => $loanId,
            'investment_id' => $investmentId,
            'created_by' => $userId,
            'type' => $type,
            'amount' => Money::decimal($amountCents),
            'balance_before' => Money::decimal($beforeCents),
            'balance_after' => Money::decimal($afterCents),
            'notes' => $notes,
        ]);
    }

    private function reverseExistingPaymentReturns(Loan $loan, ?int $userId): void
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
                throw ValidationException::withMessages([
                    'investors' => 'No se pueden reasignar inversionistas: hay retornos de pagos ya usados o reinvertidos.',
                ]);
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
                'notes' => 'Retornos revertidos por reasignacion de inversionistas',
                'metadata' => [
                    'reversed_return_movement_id' => $returnMovement->id,
                    'returned_capital' => Money::decimal($returnedCapitalCents),
                    'generated_interest' => Money::decimal($generatedInterestCents),
                ],
            ]);
        }
    }

    private function replayAppliedPaymentReturns(Loan $loan, ?int $userId): void
    {
        $movements = CollectionMovement::query()
            ->with(['allocations.installment', 'loan.investments.investor'])
            ->where('loan_id', $loan->id)
            ->where('confirmation_status', 'applied')
            ->where('affects_investors', true)
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            foreach ($movement->allocations as $allocation) {
                $installment = $allocation->installment;

                if (! $installment) {
                    continue;
                }

                $appliedCents = Money::cents($allocation->amount);
                $contractCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

                if ($appliedCents <= 0 || $contractCents <= 0) {
                    continue;
                }

                if ($movement->type === 'advance') {
                    $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();
                    $currentMonthEnd = CarbonImmutable::parse($movement->operated_on, 'America/Merida')->endOfMonth();
                    $interestCents = $dueDate->greaterThan($currentMonthEnd) ? 0 : (int) round(Money::cents($installment->interest_amount) * min(1, $appliedCents / $contractCents));
                    $principalCents = $appliedCents;
                } else {
                    $paidRatio = min(1, $appliedCents / $contractCents);
                    $principalCents = (int) round(Money::cents($installment->principal_amount) * $paidRatio);
                    $interestCents = (int) round(Money::cents($installment->interest_amount) * $paidRatio);
                }

                $this->investorReturnRecorder->record($movement->loan, $installment, $principalCents, $interestCents, $movement, $userId ?? $movement->confirmed_by ?? $movement->registered_by ?? 0);
            }
        }
    }
}
