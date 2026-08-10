<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\LoanSchedule;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Models\Client;
use App\Models\LoanApplication;
use App\Models\Operator;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoanApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $query = LoanApplication::query()->with(['client', 'operator', 'loan']);

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        }

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(function ($query) use ($search) {
                $query
                    ->where('folio', 'like', $search)
                    ->orWhereHas('client', fn ($query) => $query
                        ->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('phone', 'like', $search))
                    ->orWhereHas('operator', fn ($query) => $query->where('name', 'like', $search));
            });
        }

        return view('applications.index', [
            'applications' => $query->latest()->paginate(15)->withQueryString(),
            'kpis' => $this->indexKpis($request),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('applications.create') || $request->user()->can('applications.authorize'), 403);

        return view('applications.create', [
            'clients' => Client::query()
                ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
                ->orderBy('last_name')
                ->get(),
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('applications.create') || $request->user()->can('applications.authorize'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'first_name' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'last_name' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'phone' => ['required_without:client_id', 'nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'operator_id' => ['nullable', 'exists:operators,id'],
            'requested_capital' => ['required', 'numeric', 'min:1'],
            'term_months' => ['required', 'integer', 'min:1'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->user()->hasRole('operador-cartera')) {
            $data['operator_id'] = $request->user()->operatorProfile?->id;
        }

        $client = ($data['client_id'] ?? null)
            ? Client::query()->findOrFail($data['client_id'])
            : Client::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $data['operator_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'status' => 'prospect',
            ]);

        $application = LoanApplication::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'operator_id' => $data['operator_id'] ?? $client->operator_id,
            'requested_capital' => $data['requested_capital'],
            'monthly_rate' => '0.000000',
            'term_months' => $data['term_months'],
            'payment_day' => $data['payment_day'],
            'status' => 'submitted',
            'notes' => $data['notes'] ?? null,
        ]);
        $application->update([
            'folio' => sprintf('SOL-%s-%04d', now('America/Merida')->format('y'), $application->id),
        ]);

        return redirect()->route('applications.show', $application)->with('status', 'Solicitud enviada para revision.');
    }

    public function show(Request $request, LoanApplication $application, LoanScheduleCalculator $calculator): View
    {
        $this->authorizeApplicationAccess($request, $application);

        $conditions = $this->conditionsFor($application);

        $schedule = $calculator->calculate($conditions);
        $openingFeeCents = $this->openingFeeCents((float) $conditions['capital'], $conditions['opening_fee_type'], (float) $conditions['opening_fee_value']);
        $displaySchedule = $this->scheduleWithOpeningFee($schedule->installments, $openingFeeCents);

        return view('applications.show', [
            'application' => $application->load(['client', 'operator', 'loan']),
            'schedule' => $schedule,
            'displaySchedule' => $displaySchedule,
            'conditions' => $conditions,
            'rateLabel' => $conditions['rate_type'] === 'annual' ? 'Tasa anual aplicada' : 'Tasa mensual aplicada',
            'rateValue' => number_format((float) $conditions['rate_value'], 2).'%',
            'interestCalculationLabel' => $this->interestCalculationLabel($conditions['interest_calculation_method'] ?? 'fixed_principal'),
            'openingFeeLabel' => $this->openingFeeLabel($conditions['opening_fee_type'], (float) $conditions['opening_fee_value']),
            'openingFeeAmount' => Money::decimal($openingFeeCents),
            'contractTotalWithFee' => Money::decimal($schedule->contractTotalCents + $openingFeeCents),
        ]);
    }

    public function simulate(Request $request, LoanApplication $application): RedirectResponse
    {
        abort_unless($request->user()->can('applications.authorize'), 403);

        $application->update([
            'approved_conditions' => $this->validatedConditions($request),
            'rejected_reason' => null,
        ]);

        return redirect()->route('applications.show', $application)->with('status', 'Condiciones simuladas y guardadas en la solicitud.');
    }

    public function approve(Request $request, LoanApplication $application): RedirectResponse
    {
        abort_unless($request->user()->can('applications.authorize'), 403);

        $conditions = $request->has('capital')
            ? $this->validatedConditions($request)
            : ($application->approved_conditions ?: $this->conditionsFor($application));

        $application->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now('America/Merida'),
            'approved_conditions' => $conditions,
            'rejected_reason' => null,
        ]);

        return redirect()->route('applications.show', $application)->with('status', 'Solicitud autorizada.');
    }

    public function reject(Request $request, LoanApplication $application): RedirectResponse
    {
        abort_unless($request->user()->can('applications.authorize'), 403);

        $data = $request->validate([
            'rejected_reason' => ['required', 'string', 'max:1000'],
        ]);

        $application->update([
            'status' => 'rejected',
            'rejected_reason' => $data['rejected_reason'],
        ]);

        return redirect()->route('applications.show', $application)->with('status', 'Solicitud rechazada.');
    }

    public function start(Request $request, LoanApplication $application, LoanFormalizer $formalizer): RedirectResponse
    {
        $this->authorizeApplicationAccess($request, $application);
        abort_unless($application->status === 'approved', 422);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
        ]);

        $conditions = $application->approved_conditions;
        $loan = $formalizer->create($application->client, [
            'operator_id' => $application->operator_id,
            'loan_application_id' => $application->id,
            'capital' => $conditions['capital'],
            'monthly_rate' => $conditions['monthly_rate'],
            'administration_fee' => $conditions['administration_fee'] ?? '0.00',
            'administration_fee_type' => $conditions['administration_fee_type'] ?? 'monthly',
            'vat_enabled' => $conditions['vat_enabled'] ?? true,
            'interest_calculation_method' => $conditions['interest_calculation_method'] ?? 'fixed_principal',
            'term_months' => (int) $conditions['term_months'],
            'payment_day' => (int) $conditions['payment_day'],
            'start_date' => $data['start_date'],
        ]);

        $application->update([
            'status' => 'started',
            'started_at' => now('America/Merida'),
            'loan_id' => $loan->id,
        ]);

        return redirect()->route('loans.show', $loan)->with('status', 'Solicitud comenzada y prestamo creado.');
    }

    private function authorizeApplicationAccess(Request $request, LoanApplication $application): void
    {
        if ($request->user()->hasRole('operador-cartera') && $application->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function conditionsFor(LoanApplication $application): array
    {
        return array_merge([
            'capital' => $application->requested_capital,
            'rate_type' => 'monthly',
            'rate_value' => 2,
            'monthly_rate' => '0.020000',
            'administration_fee' => '0.00',
            'administration_fee_type' => 'monthly',
            'vat_enabled' => true,
            'interest_calculation_method' => 'fixed_principal',
            'term_months' => $application->term_months,
            'payment_day' => $application->payment_day,
            'opening_fee_type' => 'none',
            'opening_fee_value' => 0,
            'opening_fee_amount' => '0.00',
            'start_date' => now('America/Merida')->toDateString(),
        ], $application->approved_conditions ?: []);
    }

    private function validatedConditions(Request $request): array
    {
        $data = $request->validate([
            'capital' => ['required', 'numeric', 'min:1'],
            'term_months' => ['required', 'integer', 'min:1'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'rate_type' => ['required', 'in:monthly,annual'],
            'rate_value' => ['required', 'numeric', 'min:0'],
            'administration_fee' => ['nullable', 'numeric', 'min:0'],
            'vat_enabled' => ['nullable', 'boolean'],
            'interest_calculation_method' => ['required', 'in:fixed_principal,outstanding_balance'],
            'opening_fee_type' => ['required', 'in:none,percent,fixed'],
            'opening_fee_value' => ['required', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
        ]);

        $data['monthly_rate'] = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['administration_fee_type'] = 'monthly';
        $data['vat_enabled'] = $request->boolean('vat_enabled', true);
        $data['administration_fee'] = number_format((float) ($data['administration_fee'] ?? 0), 2, '.', '');
        $data['opening_fee_amount'] = Money::decimal($this->openingFeeCents((float) $data['capital'], $data['opening_fee_type'], (float) $data['opening_fee_value']));

        return $data;
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    private function openingFeeCents(float $capital, string $type, float $value): int
    {
        return match ($type) {
            'percent' => (int) round($capital * ($value / 100) * 100),
            'fixed' => (int) round($value * 100),
            default => 0,
        };
    }

    private function openingFeeLabel(string $type, float $value): string
    {
        return match ($type) {
            'percent' => 'Porcentaje '.number_format($value, 2).'%',
            'fixed' => 'Monto fijo '.Money::mxn($value),
            default => 'Sin comision',
        };
    }

    private function interestCalculationLabel(string $method): string
    {
        return $method === 'outstanding_balance' ? 'Saldo insoluto' : 'Fijo sobre capital';
    }

    private function scheduleWithOpeningFee(array $installments, int $openingFeeCents): array
    {
        if ($openingFeeCents === 0 || $installments === []) {
            return $installments;
        }

        $installments[0]['amount_cents'] += $openingFeeCents;
        $installments[0]['amount'] = LoanSchedule::formatCents($installments[0]['amount_cents']);

        return $installments;
    }

    private function indexKpis(Request $request): array
    {
        $applications = LoanApplication::query()
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
            ->get();
        $authorized = $applications->whereIn('status', ['approved', 'started']);
        $authorizedCapitalCents = $authorized->sum(fn (LoanApplication $application) => Money::cents($application->approved_conditions['capital'] ?? $application->requested_capital));
        $authorizedFeesCents = $authorized->sum(fn (LoanApplication $application) => Money::cents($application->approved_conditions['opening_fee_amount'] ?? 0));

        return [
            ['title' => 'Montos solicitados', 'value' => Money::mxn(Money::decimal($applications->sum(fn (LoanApplication $application) => Money::cents($application->requested_capital)))), 'caption' => 'Total pedido en solicitudes', 'color' => 'blue'],
            ['title' => 'Montos autorizados', 'value' => Money::mxn(Money::decimal($authorizedCapitalCents)), 'caption' => 'Capital aprobado', 'color' => 'green'],
            ['title' => 'Comisiones autorizadas', 'value' => Money::mxn(Money::decimal($authorizedFeesCents)), 'caption' => 'Aperturas aprobadas', 'color' => 'yellow'],
            ['title' => 'Solicitudes', 'value' => number_format($applications->count()), 'caption' => 'Registros capturados', 'color' => 'orange'],
            ['title' => 'Autorizadas', 'value' => number_format($authorized->count()), 'caption' => 'Listas para comenzar', 'color' => 'green'],
        ];
    }
}
