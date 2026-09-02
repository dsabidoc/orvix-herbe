<?php

namespace App\Http\Controllers;

use App\Domain\Investors\InvestorDashboardMetrics;
use App\Domain\Loans\LoanSettlementService;
use App\Models\CollectionMovement;
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
    public function __invoke(Request $request, LoanSettlementService $settlementService, InvestorDashboardMetrics $investorMetrics): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->can('investments.view-own') && ! $user->can('investors.manage')) {
            return redirect()->route('investors.index');
        }

        $periodType = $request->string('period_type')->toString() === 'year' ? 'year' : 'month';
        $requestedPeriod = $request->string('period')->toString();
        $currentPeriod = CarbonImmutable::now('America/Merida');
        $period = $periodType === 'year'
            ? (preg_match('/^(19|20|21)\d{2}(?:-\d{2})?$/', $requestedPeriod) ? substr($requestedPeriod, 0, 4) : $currentPeriod->format('Y'))
            : (preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/', $requestedPeriod) ? $requestedPeriod : $currentPeriod->format('Y-m'));
        $periodDate = $periodType === 'year'
            ? CarbonImmutable::createFromFormat('Y-m-d', $period.'-01-01', 'America/Merida')
            : CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', 'America/Merida');
        $periodStart = $periodType === 'year' ? $periodDate->startOfYear() : $periodDate->startOfMonth();
        $periodEnd = $periodType === 'year' ? $periodDate->endOfYear() : $periodDate->endOfMonth();
        $activeLoansQuery = $this->visibleLoanQuery($request);
        $activeLoansCount = (clone $activeLoansQuery)->where('is_frozen', false)->count();
        $frozenLoansCount = (clone $activeLoansQuery)->where('is_frozen', true)->count();
        $concludedLoansCount = $this->scopedLoanQuery($request)->where('status', '!=', 'active')->count();
        $collectableLoans = (clone $activeLoansQuery)->where('is_frozen', false)->get();
        $collectableLoanIds = $collectableLoans->modelKeys();
        $today = CarbonImmutable::now('America/Merida')->startOfDay();
        $selectedInvestor = ! $user->hasRole('operador-cartera') && $request->filled('investor_id')
            ? Investor::query()->find($request->integer('investor_id'))
            : null;

        if ($selectedInvestor) {
            $metrics = $investorMetrics->calculate($selectedInvestor, $collectableLoans, $today, $periodStart, $periodEnd);
            $settleTodayCents = $metrics['settle_today_cents'];
            $expectedPeriodCents = $metrics['expected_period_cents'];
            $collectedPeriodCents = $metrics['collected_period_cents'];
            $overdueCents = $metrics['overdue_cents'];
        } else {
            $settleTodayCents = $collectableLoans
                ->sum(fn (Loan $loan) => (int) $settlementService->quote($loan, $today)['total_cents']);
            $expectedPeriodCents = $this->operationalScheduledCents(
                Installment::query()
                    ->whereIn('loan_id', $collectableLoanIds)
                    ->whereBetween('due_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            );
            $collectedPeriodCents = (int) round(CollectionMovement::query()
                ->whereIn('loan_id', $collectableLoanIds)
                ->whereIn('confirmation_status', ['reported', 'applied'])
                ->whereBetween('operated_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->sum('contract_amount') * 100);
            $overdueCents = $this->operationalPendingCents(
                Installment::query()
                    ->whereIn('loan_id', $collectableLoanIds)
                    ->whereDate('due_date', '<', $periodStart->toDateString())
                    ->where('remaining_amount', '>', 0)
            );
        }
        $pendingPeriodCents = $expectedPeriodCents - $collectedPeriodCents;

        $loans = (clone $activeLoansQuery)
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

        $quickCollectionLoans = (clone $activeLoansQuery)
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
                ['title' => 'Prestamos activos', 'value' => number_format($activeLoansCount), 'caption' => 'Prestamos activos y cobrables', 'cents' => 0, 'color' => 'green', 'chartable' => false],
                ['title' => 'Prestamos congelados', 'value' => number_format($frozenLoansCount), 'caption' => 'Prestamos temporalmente detenidos', 'cents' => 0, 'color' => 'yellow', 'chartable' => false],
                ['title' => 'Prestamos concluidos', 'value' => number_format($concludedLoansCount), 'caption' => 'Prestamos liquidados o concluidos', 'cents' => 0, 'color' => 'blue', 'chartable' => false],
                ['title' => 'Total a liquidar hoy', 'value' => Money::mxn(Money::decimal((int) $settleTodayCents)), 'caption' => $selectedInvestor ? 'Capital aportado e intereses correspondientes' : 'Capital futuro e intereses vigentes', 'cents' => (int) $settleTodayCents, 'color' => 'blue'],
                ['title' => 'Esperado del periodo', 'value' => Money::mxn(Money::decimal((int) $expectedPeriodCents)), 'caption' => $selectedInvestor ? 'Programado para su participacion' : 'Total programado originalmente', 'cents' => (int) $expectedPeriodCents, 'color' => 'yellow'],
                ['title' => 'Cobrado del periodo', 'value' => Money::mxn(Money::decimal((int) $collectedPeriodCents)), 'caption' => $selectedInvestor ? 'Retornos aplicados al inversionista' : 'Pagos aplicados en el periodo', 'cents' => (int) $collectedPeriodCents, 'color' => 'green'],
                ['title' => 'Pendiente por cobrar', 'value' => Money::mxn(Money::decimal((int) $pendingPeriodCents)), 'caption' => $selectedInvestor ? 'Esperado menos retornado' : 'Esperado menos cobrado', 'cents' => (int) $pendingPeriodCents, 'color' => 'orange'],
                ['title' => 'Total vencidos', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => $selectedInvestor ? 'Capital e interes vencido correspondientes' : 'Abono e interes vencido', 'cents' => (int) $overdueCents, 'color' => 'red'],
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
        return $this->scopedLoanQuery($request)->where('status', 'active');
    }

    private function scopedLoanQuery(Request $request)
    {
        $query = Loan::query();

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

    private function operationalScheduledCents($query): int
    {
        return (int) $query
            ->get(['principal_amount', 'interest_amount'])
            ->sum(fn (Installment $installment) => Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount));
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
