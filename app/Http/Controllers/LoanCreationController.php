<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\RoundedLoanQuoteCalculator;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Investor;
use App\Models\Loan;
use App\Models\Operator;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoanCreationController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        return view('loans.create', [
            'clients' => Client::query()->orderBy('last_name')->orderBy('first_name')->get(),
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'terms' => $this->roundedTerms(),
        ]);
    }

    public function store(Request $request, LoanFormalizer $formalizer): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        $data = $request->validate(
            [
                'client_id' => ['nullable', 'exists:clients,id'],
                'first_name' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
                'last_name' => ['nullable', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:160'],
                'operator_id' => ['required', 'exists:operators,id'],
                'capital' => ['required', 'numeric', 'min:1'],
                'rate_type' => ['required', 'in:monthly,annual'],
                'rate_value' => ['required', 'numeric', 'min:0'],
                'administration_fee' => ['nullable', 'numeric', 'min:0'],
                'vat_enabled' => ['nullable', 'boolean'],
                'interest_calculation_method' => ['required', 'in:fixed_principal,outstanding_balance'],
                'term_months' => ['required', 'integer', 'min:1'],
                'start_date' => ['required', 'date'],
                'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
                'brand' => ['nullable', 'string', 'max:80'],
                'model' => ['nullable', 'string', 'max:120'],
                'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
                'plates' => ['nullable', 'string', 'max:40'],
                'vin' => ['nullable', 'string', 'max:80'],
                'documents.*' => ['nullable', 'file', 'max:10240'],
            ],
            [
                'first_name.required_without' => 'Captura el nombre del cliente o selecciona un cliente existente.',
                'operator_id.required' => 'Selecciona el operador del prestamo.',
                'capital.required' => 'Captura el capital del prestamo.',
                'rate_type.required' => 'Selecciona si la tasa es mensual o anual.',
                'rate_value.required' => 'Captura el porcentaje de tasa del prestamo.',
                'term_months.required' => 'Captura el plazo en meses.',
                'payment_day.required' => 'Captura el dia de pago.',
                'start_date.required' => 'Captura la fecha de inicio.',
            ],
        );

        $client = ($data['client_id'] ?? null)
            ? Client::query()->findOrFail($data['client_id'])
            : Client::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $data['operator_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? null,
                'status' => 'active',
            ]);

        $vehicle = $formalizer->vehicleFor($client, $data);
        $monthlyRate = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $administrationFee = number_format((float) ($data['administration_fee'] ?? 0), 2, '.', '');
        $loan = $formalizer->create($client, [
            'operator_id' => $data['operator_id'],
            'vehicle_id' => $vehicle->id,
            'capital' => $data['capital'],
            'monthly_rate' => $monthlyRate,
            'administration_fee' => $administrationFee,
            'administration_fee_type' => 'monthly',
            'vat_enabled' => $request->boolean('vat_enabled', true),
            'interest_calculation_method' => $data['interest_calculation_method'],
            'term_months' => (int) $data['term_months'],
            'start_date' => $data['start_date'],
            'payment_day' => (int) $data['payment_day'],
        ]);

        foreach ($request->file('documents', []) as $file) {
            $path = $file->store('expedientes/'.$loan->public_id, 'local');
            Document::query()->create([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $loan->id,
                'client_id' => $client->id,
                'uploaded_by' => $request->user()->id,
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'status' => 'delivered',
            ]);
        }

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo creado con calendario y expediente.');
    }

    public function quote(Request $request, RoundedLoanQuoteCalculator $calculator): View
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        $data = $this->validatedRoundedData($request);
        $data['monthly_rate'] = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['first_payment_date'] = $data['first_payment_date'] ?? $data['start_date'];
        $quote = $calculator->quote([
            'capital' => $data['capital'],
            'monthly_rate' => $data['monthly_rate'],
            'collection_fee' => $data['administration_fee'] ?? '0.00',
            'term_months' => (int) $data['term_months'],
            'first_payment_date' => $data['first_payment_date'],
        ]);

        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'rounded_loan_quoted',
            'auditable_type' => 'rounded_loan_quote',
            'after' => [
                'input' => $data,
                'totals' => $quote['input'],
            ],
        ]);

        return view('loans.quote-rounded', [
            'data' => $data,
            'quote' => $quote,
        ]);
    }

    public function confirmRounded(Request $request, RoundedLoanQuoteCalculator $calculator): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        $data = $this->validatedRoundedData($request) + $request->validate([
            'selected_option' => ['required', 'in:tens,hundreds'],
        ]);
        $data['monthly_rate'] = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['first_payment_date'] = $data['first_payment_date'] ?? $data['start_date'];
        $quote = $calculator->quote([
            'capital' => $data['capital'],
            'monthly_rate' => $data['monthly_rate'],
            'collection_fee' => $data['administration_fee'] ?? '0.00',
            'term_months' => (int) $data['term_months'],
            'first_payment_date' => $data['first_payment_date'],
        ]);
        $option = $quote['options'][$data['selected_option']];

        $loan = DB::transaction(function () use ($request, $data, $quote, $option) {
            $client = $this->clientFor($data);
            $vehicle = app(LoanFormalizer::class)->vehicleFor($client, $data);

            $loan = Loan::query()->create([
                'public_id' => (string) Str::ulid(),
                'folio' => 'ORV-'.now('America/Merida')->format('ymd').'-'.str_pad((string) (Loan::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'client_id' => $client->id,
                'operator_id' => $data['operator_id'] ?? $client->operator_id,
                'vehicle_id' => $vehicle?->id,
                'calculation_method' => 'rounded',
                'capital' => Money::decimal($quote['input']['capital_cents']),
                'monthly_rate' => $data['monthly_rate'],
                'administration_fee' => Money::decimal($quote['input']['collection_fee_cents']),
                'administration_fee_type' => 'monthly',
                'vat_enabled' => false,
                'interest_calculation_method' => 'fixed_principal',
                'rounding_multiple' => $option['rounding_multiple'],
                'interest_monthly' => Money::decimal($quote['input']['interest_monthly_cents']),
                'interest_total' => Money::decimal($quote['input']['interest_total_cents']),
                'collection_total' => Money::decimal($quote['input']['collection_total_cents']),
                'first_payment_amount' => $option['first_payment'],
                'regular_payment_amount' => $option['regular_payment'],
                'quote_snapshot' => [
                    'input' => $quote['input'],
                    'selected_option' => $data['selected_option'],
                    'option' => $option,
                    'rate_type' => $data['rate_type'],
                    'rate_value' => $data['rate_value'],
                ],
                'quoted_by' => $request->user()->id,
                'quoted_at' => now('America/Merida'),
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now('America/Merida'),
                'term_months' => (int) $data['term_months'],
                'contract_total' => Money::decimal($quote['input']['total_cents']),
                'start_date' => $data['start_date'],
                'payment_day' => (int) $data['payment_day'],
                'status' => 'active',
            ]);

            foreach ($option['installments'] as $installment) {
                $loan->installments()->create([
                    'number' => $installment['number'],
                    'due_date' => $installment['due_date'],
                    'contract_amount' => $installment['amount'],
                    'principal_amount' => $installment['principal'],
                    'administration_fee_amount' => $installment['administration_fee'],
                    'interest_amount' => $installment['interest'],
                    'interest_vat_amount' => '0.00',
                    'capital_balance' => $installment['balance'],
                    'remaining_amount' => $installment['amount'],
                    'status' => 'upcoming',
                ]);
            }

            $primaryInvestor = Investor::query()->firstOrCreate(
                ['name' => 'Herbe Rodriguez'],
                ['public_id' => (string) Str::ulid(), 'status' => 'active'],
            );
            $loan->investments()->create([
                'public_id' => (string) Str::ulid(),
                'investor_id' => $primaryInvestor->id,
                'vehicle_id' => $loan->vehicle_id,
                'amount' => $loan->capital,
                'investor_share_rate' => '1.000000',
                'administrator_share_rate' => '0.000000',
                'starts_on' => $loan->start_date,
                'status' => 'active',
                'agreement_snapshot' => ['role' => 'principal', 'capital_percent' => 100, 'interest_share_percent' => 100],
            ]);

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'rounded_loan_confirmed',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'after' => $loan->quote_snapshot,
            ]);

            return $loan;
        });

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo con redondeo creado con la opcion seleccionada.');
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRoundedData(Request $request): array
    {
        return $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'first_name' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'operator_id' => ['required', 'exists:operators,id'],
            'capital' => ['required', 'numeric', 'min:1'],
            'rate_type' => ['required', 'in:monthly,annual'],
            'rate_value' => ['required', 'numeric', 'min:0'],
            'administration_fee' => ['nullable', 'numeric', 'min:0'],
            'term_months' => ['required', 'integer', 'in:'.implode(',', $this->roundedTerms())],
            'start_date' => ['required', 'date'],
            'first_payment_date' => ['required', 'date'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'plates' => ['nullable', 'string', 'max:40'],
            'vin' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function clientFor(array $data): Client
    {
        return ($data['client_id'] ?? null)
            ? Client::query()->findOrFail($data['client_id'])
            : Client::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $data['operator_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? null,
                'status' => 'active',
            ]);
    }

    /**
     * @return list<int>
     */
    private function roundedTerms(): array
    {
        return [3, 6, 8, 10, 12, 18, 20, 24, 30, 36, 40, 48, 50, 70];
    }
}
