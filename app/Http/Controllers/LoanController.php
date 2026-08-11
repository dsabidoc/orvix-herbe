<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Domain\Loans\LoanSettlementService;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Loan;
use App\Models\Operator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoanController extends Controller
{
    public function index(Request $request): View
    {
        $query = Loan::query()
            ->with(['client', 'operator', 'vehicle', 'installments' => fn ($query) => $query->orderBy('number')])
            ->where('status', 'active');

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        }

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(function ($query) use ($search) {
                $query->where('folio', 'like', $search)
                    ->orWhereHas('client', fn ($query) => $query->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search))
                    ->orWhereHas('vehicle', fn ($query) => $query->where('model', 'like', $search)->orWhere('plates', 'like', $search)->orWhere('vin', 'like', $search));
            });
        }

        if ($request->input('bucket') === 'overdue') {
            $query->whereHas('installments', fn ($query) => $query->whereDate('due_date', '<', now('America/Merida')->toDateString())->where('remaining_amount', '>', 0));
        }

        if ($request->input('bucket') === 'today') {
            $query->whereHas('installments', fn ($query) => $query->whereDate('due_date', now('America/Merida')->toDateString())->where('remaining_amount', '>', 0));
        }

        return view('loans.index', [
            'loans' => $query->latest()->paginate(15)->withQueryString(),
            'today' => CarbonImmutable::now('America/Merida')->toDateString(),
            'kpis' => $this->kpis($request),
        ]);
    }

    public function show(Request $request, Loan $loan, LoanSettlementService $settlementService): View
    {
        $this->authorizeLoanAccess($request, $loan);

        $loan = $loan->load([
            'client',
            'operator',
            'vehicle',
            'documents',
            'investments.investor',
            'fundDisbursements.weeklyCut',
            'fundDisbursements.operator',
            'fundDisbursements.registeredBy',
            'installments' => fn ($query) => $query->with('reportedMovement')->orderBy('number'),
            'movements' => fn ($query) => $query->with(['registeredBy', 'allocations.installment'])->latest(),
        ]);

        return view('loans.show', [
            'loan' => $loan,
            'investors' => Investor::query()->where('status', 'active')->orderBy('name')->get(),
            'settlementQuote' => $loan->status === 'active' ? $settlementService->quote($loan) : null,
        ]);
    }

    public function edit(Request $request, Loan $loan): View
    {
        abort_unless($request->user()->can('loans.formalize'), 403);
        $this->authorizeLoanAccess($request, $loan);

        return view('loans.edit', [
            'loan' => $loan->load(['client', 'operator', 'vehicle', 'installments', 'movements']),
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'canEditConditions' => $this->canEditConditions($loan),
        ]);
    }

    public function update(Request $request, Loan $loan, LoanScheduleCalculator $calculator): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'operator_id' => ['required', 'exists:operators,id'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'plates' => ['nullable', 'string', 'max:40'],
            'vin' => ['nullable', 'string', 'max:80'],
            'capital' => ['required', 'numeric', 'min:1'],
            'rate_type' => ['required', 'in:monthly,annual'],
            'rate_value' => ['required', 'numeric', 'min:0'],
            'administration_fee' => ['nullable', 'numeric', 'min:0'],
            'vat_enabled' => ['nullable', 'boolean'],
            'interest_calculation_method' => ['required', 'in:fixed_principal,outstanding_balance'],
            'term_months' => ['required', 'integer', 'min:1'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'start_date' => ['required', 'date'],
            'first_payment_date' => ['required', 'date'],
        ]);

        $newMonthlyRate = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['administration_fee_type'] = 'monthly';
        $newAdministrationFee = number_format((float) ($data['administration_fee'] ?? 0), 2, '.', '');
        $data['vat_enabled'] = $request->boolean('vat_enabled', true);
        $conditionsChanged = $this->conditionsChanged($loan, $data, $newMonthlyRate);

        if ($conditionsChanged && ! $this->canEditConditions($loan)) {
            return back()
                ->withErrors(['capital' => 'Este prestamo ya tiene cobros registrados; solo puedes editar cliente, operador y vehiculo.'])
                ->withInput();
        }

        DB::transaction(function () use ($loan, $data, $newMonthlyRate, $newAdministrationFee, $conditionsChanged, $calculator) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $loan->client->update([
                'operator_id' => $data['operator_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? null,
            ]);

            $loan->vehicle?->update([
                'brand' => $data['brand'] ?? 'Sin marca',
                'model' => $data['model'] ?? 'Vehiculo',
                'year' => $data['year'] ?? null,
                'plates' => $data['plates'] ?? null,
                'vin' => $data['vin'] ?? null,
            ]);

            if (! $conditionsChanged) {
                $loan->update(['operator_id' => $data['operator_id']]);

                return;
            }

            $schedule = $calculator->calculate([
                'capital' => $data['capital'],
                'monthly_rate' => $newMonthlyRate,
                'administration_fee' => $newAdministrationFee,
                'vat_enabled' => $data['vat_enabled'],
                'interest_calculation_method' => $data['interest_calculation_method'],
                'term_months' => (int) $data['term_months'],
                'start_date' => $data['start_date'],
                'first_payment_date' => $data['first_payment_date'],
                'payment_day' => (int) $data['payment_day'],
                'rounding_increment' => 10,
                'rounding_adjustment' => 'first',
            ]);

            $loan->installments()->delete();
            $loan->update([
                'operator_id' => $data['operator_id'],
                'capital' => $schedule->capital(),
                'monthly_rate' => $newMonthlyRate,
                'administration_fee' => $newAdministrationFee,
                'administration_fee_type' => $data['administration_fee_type'],
                'vat_enabled' => $data['vat_enabled'],
                'interest_calculation_method' => $data['interest_calculation_method'],
                'term_months' => (int) $data['term_months'],
                'contract_total' => $schedule->contractTotal(),
                'start_date' => $data['start_date'],
                'first_payment_date' => $data['first_payment_date'],
                'payment_day' => (int) $data['payment_day'],
            ]);

            foreach ($schedule->installments as $installment) {
                $loan->installments()->create([
                    'number' => $installment['number'],
                    'due_date' => $installment['due_date'],
                    'contract_amount' => $installment['amount'],
                    'principal_amount' => $installment['principal'] ?? '0.00',
                    'administration_fee_amount' => $installment['administration_fee'] ?? '0.00',
                    'interest_amount' => $installment['interest'] ?? '0.00',
                    'interest_vat_amount' => $installment['interest_vat'] ?? '0.00',
                    'capital_balance' => $installment['balance'] ?? '0.00',
                    'remaining_amount' => $installment['amount'],
                    'status' => 'upcoming',
                ]);
            }

        });

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo actualizado.');
    }

    private function authorizeLoanAccess(Request $request, Loan $loan): void
    {
        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function canEditConditions(Loan $loan): bool
    {
        return ! $loan->installments()->where('applied_amount', '>', 0)->exists()
            && ! $loan->movements()->exists();
    }

    private function conditionsChanged(Loan $loan, array $data, string $newMonthlyRate): bool
    {
        return Money::cents($loan->capital) !== Money::cents($data['capital'])
            || (string) $loan->monthly_rate !== $newMonthlyRate
            || Money::cents($loan->administration_fee ?? 0) !== Money::cents($data['administration_fee'] ?? 0)
            || (bool) $loan->vat_enabled !== (bool) $data['vat_enabled']
            || $loan->interest_calculation_method !== $data['interest_calculation_method']
            || (int) $loan->term_months !== (int) $data['term_months']
            || (int) $loan->payment_day !== (int) $data['payment_day']
            || $loan->start_date->toDateString() !== CarbonImmutable::parse($data['start_date'])->toDateString()
            || ($loan->first_payment_date ?? $loan->start_date)->toDateString() !== CarbonImmutable::parse($data['first_payment_date'])->toDateString();
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    private function kpis(Request $request): array
    {
        $loanIds = Loan::query()
            ->where('status', 'active')
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
            ->pluck('id');
        $today = CarbonImmutable::now('America/Merida')->startOfDay();
        $cutPeriod = app(WeeklyCutPeriodService::class)->periodFor($today);
        $weekStart = $cutPeriod['start'];
        $weekEnd = $cutPeriod['end'];
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $remainingCents = Installment::query()->whereIn('loan_id', $loanIds)->sum('remaining_amount') * 100;
        $expectedWeekCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereBetween('due_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;
        $expectedPeriodCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('contract_amount') * 100;
        $pendingReportedCents = CollectionMovement::query()
            ->whereIn('loan_id', $loanIds)
            ->where('confirmation_status', 'reported')
            ->whereBetween(DB::raw('COALESCE(registered_at, created_at)'), [$monthStart->startOfDay(), $monthEnd->endOfDay()])
            ->sum('contract_amount') * 100;
        $overdueCents = Installment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereDate('due_date', '<', $today->toDateString())
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;

        return [
            ['title' => 'Cartera activa', 'value' => Money::mxn(Money::decimal((int) $remainingCents)), 'caption' => 'Saldo contractual pendiente', 'color' => 'blue'],
            ['title' => 'Esperado semanal', 'value' => Money::mxn(Money::decimal((int) $expectedWeekCents)), 'caption' => 'Letras vencen esta semana', 'color' => 'orange'],
            ['title' => 'Esperado del periodo', 'value' => Money::mxn(Money::decimal((int) $expectedPeriodCents)), 'caption' => 'Calendario mensual', 'color' => 'yellow'],
            ['title' => 'Reportado pendiente', 'value' => Money::mxn(Money::decimal((int) $pendingReportedCents)), 'caption' => 'Cobros aun por confirmar', 'color' => 'green'],
            ['title' => 'Vencido', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => 'Letras vencidas no aplicadas', 'color' => 'red'],
        ];
    }
}
