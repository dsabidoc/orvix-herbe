<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
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
        $today = CarbonImmutable::now('America/Merida')->startOfDay();
        $cutPeriod = app(WeeklyCutPeriodService::class)->periodFor($today);
        $weekStart = $cutPeriod['start'];
        $weekEnd = $cutPeriod['end'];

        $remainingCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->sum('remaining_amount') * 100;

        $expectedWeekCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereBetween('due_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;

        $expectedPeriodCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereBetween('due_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('contract_amount') * 100;

        $pendingReportedCents = CollectionMovement::query()
            ->whereIn('loan_id', $loanIds)
            ->where('confirmation_status', 'reported')
            ->whereBetween(DB::raw('COALESCE(registered_at, created_at)'), [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->sum('contract_amount') * 100;

        $overdueCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereDate('due_date', '<', $today->toDateString())
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;

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
            ->orderBy('folio')
            ->get();

        return view('dashboard', [
            'kpis' => [
                ['title' => 'Cartera activa', 'value' => Money::mxn(Money::decimal((int) $remainingCents)), 'caption' => 'Saldo contractual pendiente', 'cents' => (int) $remainingCents, 'color' => 'blue'],
                ['title' => 'Esperado semanal', 'value' => Money::mxn(Money::decimal((int) $expectedWeekCents)), 'caption' => 'Letras vencen esta semana', 'cents' => (int) $expectedWeekCents, 'color' => 'orange'],
                ['title' => 'Esperado del periodo', 'value' => Money::mxn(Money::decimal((int) $expectedPeriodCents)), 'caption' => $periodType === 'year' ? 'Calendario anual' : 'Calendario mensual', 'cents' => (int) $expectedPeriodCents, 'color' => 'yellow'],
                ['title' => 'Reportado pendiente', 'value' => Money::mxn(Money::decimal((int) $pendingReportedCents)), 'caption' => 'Cobros aun por confirmar', 'cents' => (int) $pendingReportedCents, 'color' => 'green'],
                ['title' => 'Vencido', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => 'Letras vencidas no aplicadas', 'cents' => (int) $overdueCents, 'color' => 'red'],
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
}
