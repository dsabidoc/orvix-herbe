<?php

namespace App\Http\Controllers;

use App\Models\Investor;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanInvestmentController extends Controller
{
    public function store(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage') || $request->user()->can('loans.formalize'), 403);

        $data = $request->validate([
            'investors' => ['nullable', 'array', 'max:8'],
            'investors.*.name' => ['nullable', 'string', 'max:160'],
            'investors.*.capital_amount' => ['nullable', 'numeric', 'min:0'],
            'investors.*.interest_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $participants = collect($data['investors'] ?? [])
            ->filter(fn (array $participant) => filled($participant['name'] ?? null))
            ->map(fn (array $participant) => [
                'name' => trim($participant['name']),
                'capital_cents' => Money::cents($participant['capital_amount'] ?? 0),
                'interest_share_percent' => (float) ($participant['interest_share_percent'] ?? 0),
            ])
            ->values();

        $capitalCents = Money::cents($loan->capital);
        $externalCapitalCents = $participants->sum('capital_cents');
        $externalInterestShare = $participants->sum('interest_share_percent');

        if ($externalCapitalCents > $capitalCents) {
            return back()->withErrors(['investors' => 'El capital aportado por inversionistas no puede ser mayor al capital del prestamo.'])->withInput();
        }

        if ($externalInterestShare > 100) {
            return back()->withErrors(['investors' => 'El porcentaje total de intereses no puede ser mayor a 100%.'])->withInput();
        }

        DB::transaction(function () use ($loan, $participants, $capitalCents, $externalCapitalCents, $externalInterestShare) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $loan->investments()->delete();
            $primary = $this->primaryInvestor();
            $this->createInvestment(
                loan: $loan,
                investor: $primary,
                amountCents: $capitalCents - $externalCapitalCents,
                interestSharePercent: 100 - $externalInterestShare,
                role: 'principal',
            );

            foreach ($participants as $participant) {
                $investor = Investor::query()->firstOrCreate(
                    ['name' => $participant['name']],
                    [
                        'public_id' => (string) Str::ulid(),
                        'status' => 'active',
                    ],
                );

                $this->createInvestment(
                    loan: $loan,
                    investor: $investor,
                    amountCents: $participant['capital_cents'],
                    interestSharePercent: $participant['interest_share_percent'],
                    role: 'inversionista',
                );
            }
        });

        return redirect()->route('loans.show', $loan)->with('status', 'Inversionistas actualizados para este prestamo.');
    }

    private function primaryInvestor(): Investor
    {
        return Investor::query()->firstOrCreate(
            ['name' => 'Herbe Rodriguez'],
            [
                'public_id' => (string) Str::ulid(),
                'status' => 'active',
            ],
        );
    }

    private function createInvestment(Loan $loan, Investor $investor, int $amountCents, float $interestSharePercent, string $role): void
    {
        $interestShareRate = $interestSharePercent / 100;

        $loan->investments()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investor->id,
            'vehicle_id' => $loan->vehicle_id,
            'amount' => Money::decimal(max(0, $amountCents)),
            'investor_share_rate' => number_format($interestShareRate, 6, '.', ''),
            'administrator_share_rate' => number_format(max(0, 1 - $interestShareRate), 6, '.', ''),
            'starts_on' => $loan->start_date,
            'status' => 'active',
            'agreement_snapshot' => [
                'role' => $role,
                'capital_percent' => Money::cents($loan->capital) > 0 ? round(($amountCents / Money::cents($loan->capital)) * 100, 4) : 0,
                'interest_share_percent' => $interestSharePercent,
            ],
        ]);
    }
}
