<?php

namespace App\Domain\Investors;

use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestmentAllocationService
{
    /**
     * @param  array<int, array<string, mixed>>  $input
     * @return Collection<int, array{investor:Investor, investor_id:int, capital_cents:int, interest_share_percent:float}>
     */
    public function participants(array $input, int $capitalCents, ?Loan $loan = null): Collection
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

        $this->validateParticipants($participants, $capitalCents, $loan);

        return $participants;
    }

    /**
     * @param  Collection<int, array{investor:Investor|null, investor_id:int, capital_cents:int, interest_share_percent:float}>  $participants
     */
    public function validateParticipants(Collection $participants, int $capitalCents, ?Loan $loan = null): void
    {
        if ($participants->isEmpty()) {
            throw ValidationException::withMessages([
                'investors' => 'Selecciona al menos un inversionista para crear el prestamo.',
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

        if ($participants->contains(fn (array $row) => $row['capital_cents'] <= 0)) {
            throw ValidationException::withMessages([
                'investors' => 'Cada inversionista debe aportar un monto de capital mayor a cero.',
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

        foreach ($participants as $participant) {
            $availableCents = $this->availableCentsForInvestor($participant['investor'], $loan);

            if ($participant['capital_cents'] > $availableCents) {
                throw ValidationException::withMessages([
                    'investors' => $participant['investor']->name.' no tiene capital disponible suficiente. Disponible: '.Money::mxn(Money::decimal($availableCents)).'.',
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $input
     */
    public function assignFromInput(Loan $loan, array $input, ?int $userId = null): void
    {
        $participants = $this->participants($input, Money::cents($loan->capital), $loan);

        DB::transaction(function () use ($loan, $participants, $userId) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

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
        });
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

        if ($afterCents < 0) {
            throw ValidationException::withMessages([
                'amount' => 'El inversionista no tiene capital disponible suficiente.',
            ]);
        }

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
}
