<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanSettlementService;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LoanSettlementService $settlementService): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->can('investments.view-own') && ! $user->can('investors.manage')) {
            return redirect()->route('investors.index');
        }

        $periodType = $request->string('period_type')->toString() === 'year' ? 'year' : 'month';
        $period = $request->string('period')->toString() ?: CarbonImmutable::now('America/Merida')->format($periodType === 'year' ? 'Y' : 'Y-m');
        $periodDate = $periodType === 'year'
            ? CarbonImmutable::createFromFormat('Y-m-d', $period.'-01-01', 'America/Merida')
            : CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', 'America/Merida');
        $periodStart = $periodType === 'year' ? $periodDate->startOfYear() : $periodDate->startOfMonth();
        $periodEnd = $periodType === 'year' ? $periodDate->endOfYear() : $periodDate->endOfMonth();
        $loanIds = $this->visibleLoanQuery($request)->pluck('id');
        $collectableLoanIds = $this->visibleLoanQuery($request)->where('is_frozen', false)->pluck('id');
        $today = CarbonImmutable::now('America/Merida')->startOfDay();
        $settleTodayCents = Loan::query()
            ->whereIn('id', $loanIds)
            ->get()
            ->sum(fn (Loan $loan) => (int) $settlementService->quote($loan, $today)['total_cents']);

        $expectedPeriodCents = $this->operationalPendingCents(
            Installment::query()
                ->whereIn('loan_id', $collectableLoanIds)
                ->whereBetween('due_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->where('remaining_amount', '>', 0)
        );

        $overdueCents = $this->operationalPendingCents(
            Installment::query()
                ->whereIn('loan_id', $collectableLoanIds)
                ->whereDate('due_date', '<', $today->toDateString())
                ->where('remaining_amount', '>', 0)
        );

        $loans = $this->visibleLoanQuery($request)
            ->with(['client', 'operator', 'vehicle', 'installments' => fn ($query) => $query->orderBy('number')])
            ->latest()
            ->limit(8)
            ->get();

        $cuts = WeeklyCut::query()
            ->with('operator')
            ->when($user->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $user->operatorProfile?->id))
            ->when($request->filled('operator_id') && ! $user->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->integer('operator_id')))
            ->whereBetween('period_starts_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->latest()
            ->limit(5)
            ->get();

        $quickCollectionLoans = $this->visibleLoanQuery($request)
            ->with([
                'client',
                'vehicle',
                'operator',
                'installments' => fn ($query) => $query
                    ->with('reportedMovement')
                    ->where('remaining_amount', '>', 0)
                    ->whereDoesntHave('reportedMovement')
                    ->orderBy('due_date')
                    ->orderBy('number'),
            ])
            ->whereHas('installments', fn ($query) => $query
                ->where('remaining_amount', '>', 0)
                ->whereDoesntHave('reportedMovement'))
            ->where('is_frozen', false)
            ->orderBy('folio')
            ->get();

        return view('dashboard', [
            'kpis' => [
                ['title' => 'Total a liquidar hoy', 'value' => Money::mxn(Money::decimal((int) $settleTodayCents)), 'caption' => 'Capital futuro e intereses vigentes', 'cents' => (int) $settleTodayCents, 'color' => 'blue'],
                ['title' => 'Esperado del periodo', 'value' => Money::mxn(Money::decimal((int) $expectedPeriodCents)), 'caption' => $periodType === 'year' ? 'Abono e interes anual' : 'Abono e interes mensual', 'cents' => (int) $expectedPeriodCents, 'color' => 'yellow'],
                ['title' => 'Total vencidos', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => 'Abono e interes vencido', 'cents' => (int) $overdueCents, 'color' => 'red'],
            ],
            'loans' => $loans,
            'cuts' => $cuts,
            'quickCollectionLoans' => $quickCollectionLoans,
            'operators' => Operator::query()
                ->when($user->hasRole('operador-cartera'), fn ($query) => $query->whereKey($user->operatorProfile?->id))
                ->withCount('loans')
                ->get(),
            'investors' => $user->hasRole('operador-cartera')
                ? collect()
                : Investor::query()->where('status', 'active')->orderBy('name')->get(),
            'filters' => [
                'operator_id' => $request->input('operator_id'),
                'investor_id' => $request->input('investor_id'),
                'period_type' => $periodType,
                'period' => $period,
            ],
        ]);
    }

    private function visibleLoanQuery(Request $request)
    {
        $query = Loan::query()->where('status', 'active');

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        } elseif ($request->filled('operator_id')) {
            $query->where('operator_id', $request->integer('operator_id'));
        }

        if (! $request->user()->hasRole('operador-cartera') && $request->filled('investor_id')) {
            $query->whereHas('investments', fn ($query) => $query
                ->where('investor_id', $request->integer('investor_id'))
                ->where('status', 'active'));
        }

        return $query;
    }

    private function operationalPendingCents($query): int
    {
        return (int) $query
            ->get(['principal_amount', 'interest_amount', 'contract_amount', 'remaining_amount'])
            ->sum(fn (Installment $installment) => $this->installmentOperationalPendingCents($installment));
    }

    private function installmentOperationalPendingCents(Installment $installment): int
    {
        $remainingCents = Money::cents($installment->remaining_amount);

        if ($remainingCents <= 0) {
            return 0;
        }

        $operationalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

        if ($operationalCents <= 0) {
            return $remainingCents;
        }

        return min($remainingCents, $operationalCents);
    }
}
