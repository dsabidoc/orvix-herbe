<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Investors\InvestmentAllocationService;
use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Domain\Loans\RoundedLoanQuoteCalculator;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\FundDisbursement;
use App\Models\Investor;
use App\Models\Loan;
use App\Models\LoanInvoiceMovement;
use App\Models\Operator;
use App\Models\OperatorLedgerEntry;
use App\Models\WeeklyCut;
use App\Support\LoanFolios;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoanCreationController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->can('loans.formalize'), 403);
        $weeklyCut = $request->filled('weekly_cut_id')
            ? WeeklyCut::query()->whereKey($request->integer('weekly_cut_id'))->first()
            : null;

        return view('loans.create', [
            'clients' => Client::query()->orderBy('last_name')->orderBy('first_name')->get(),
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'investors' => Investor::availableForFunding()->get(),
            'terms' => $this->roundedTerms(),
            'selectedOperatorId' => $request->integer('operator_id') ?: $weeklyCut?->operator_id,
            'weeklyCut' => $weeklyCut,
        ]);
    }

    public function restoreCreate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        return redirect()->route('loans.create')->withInput($request->except('_token'));
    }

    public function store(Request $request, LoanFormalizer $formalizer, InvestmentAllocationService $allocator): RedirectResponse
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
                'first_payment_date' => ['required', 'date'],
                'weekly_cut_id' => ['nullable', 'exists:weekly_cuts,id'],
                'disbursement_delivered_on' => ['nullable', 'date'],
                'disbursement_notes' => ['nullable', 'string', 'max:500'],
                'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
                'guarantor_name' => ['nullable', 'string', 'max:180'],
                'guarantor_address' => ['nullable', 'string', 'max:1000'],
                'guarantor_phone' => ['nullable', 'string', 'max:40'],
                'delinquency_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'delinquency_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'brand' => ['nullable', 'string', 'max:80'],
                'model' => ['nullable', 'string', 'max:120'],
                'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
                'plates' => ['nullable', 'string', 'max:40'],
                'vin' => ['nullable', 'string', 'size:17'],
                'invoice_file' => ['nullable', 'file', 'mimes:pdf', 'max:102400'],
                'invoice_holder' => ['nullable', 'in:Caja,Recepcion,Operador'],
                'documents.*' => ['nullable', 'file', 'max:10240'],
                'investors' => ['nullable', 'array', 'max:8'],
                'investors.*.investor_id' => ['nullable', 'exists:investors,id'],
                'investors.*.capital_amount' => ['nullable', 'numeric', 'min:0'],
                'investors.*.interest_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],
            [
                'first_name.required_without' => 'Captura el nombre del cliente o selecciona un cliente existente.',
                'operator_id.required' => 'Selecciona el operador del prestamo.',
                'capital.required' => 'Captura el capital del prestamo.',
                'rate_type.required' => 'Selecciona si la tasa es mensual o anual.',
                'rate_value.required' => 'Captura el porcentaje de tasa del prestamo.',
                'term_months.required' => 'Captura el plazo en meses.',
                'payment_day.required' => 'Captura el dia de pago.',
                'start_date.required' => 'Captura la fecha de compra.',
                'first_payment_date.required' => 'Captura la fecha de vencimiento.',
                'disbursement_delivered_on.required' => 'Captura la fecha real de entrega del dinero.',
                'investors.required' => 'Selecciona al menos un inversionista para crear el prestamo.',
            ],
        );

        if ($allocator->hasParticipants($data['investors'] ?? [])) {
            $allocator->participants($data['investors'], Money::cents($data['capital']), allowEmpty: true);
        }

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
            'first_payment_date' => $data['first_payment_date'],
            'payment_day' => (int) $data['payment_day'],
            'guarantor_name' => $data['guarantor_name'] ?? null,
            'guarantor_address' => $data['guarantor_address'] ?? null,
            'guarantor_phone' => $data['guarantor_phone'] ?? null,
            'delinquency_rate' => number_format((float) ($data['delinquency_rate'] ?? 0), 4, '.', ''),
            'delinquency_grace_days' => (int) ($data['delinquency_grace_days'] ?? 0),
            'investors' => $data['investors'] ?? [],
            'created_by' => $request->user()->id,
        ]);

        $this->recordFundDisbursement($loan, $data, $request->user()->id);

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

        if ($request->hasFile('invoice_file')) {
            $this->attachUploadedInvoice($loan, $request, $data['invoice_holder'] ?? 'Recepcion');
        }

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo creado con calendario y expediente.');
    }

    public function quote(Request $request, RoundedLoanQuoteCalculator $roundedCalculator, LoanScheduleCalculator $regularCalculator): View
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        $data = $this->validatedRoundedData($request);
        $this->captureTempInvoice($request, $data);
        $data['monthly_rate'] = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['first_payment_date'] = $data['first_payment_date'] ?? $data['start_date'];
        $quote = ($data['calculation_method'] ?? 'regular') === 'rounded'
            ? $roundedCalculator->quote([
                'capital' => $data['capital'],
                'monthly_rate' => $data['monthly_rate'],
                'collection_fee' => $data['administration_fee'] ?? '0.00',
                'term_months' => (int) $data['term_months'],
                'first_payment_date' => $data['first_payment_date'],
            ])
            : $this->regularQuote($data, $regularCalculator);

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
            'investors' => Investor::availableForFunding()->get(),
        ]);
    }

    public function confirmRounded(Request $request, RoundedLoanQuoteCalculator $calculator, InvestmentAllocationService $allocator, LoanFormalizer $formalizer): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);

        $data = $this->validatedRoundedData($request) + $request->validate([
            'selected_option' => ['required', 'in:regular,tens,hundreds'],
            'investors' => ['nullable', 'array', 'max:8'],
            'investors.*.investor_id' => ['nullable', 'exists:investors,id'],
            'investors.*.capital_amount' => ['nullable', 'numeric', 'min:0'],
            'investors.*.interest_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $data['monthly_rate'] = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['first_payment_date'] = $data['first_payment_date'] ?? $data['start_date'];

        if (($data['calculation_method'] ?? 'regular') === 'regular') {
            if ($allocator->hasParticipants($data['investors'] ?? [])) {
                $allocator->participants($data['investors'], Money::cents($data['capital']), allowEmpty: true);
            }

            $loan = DB::transaction(function () use ($request, $data, $formalizer) {
                $client = $this->clientFor($data);
                $vehicle = $formalizer->vehicleFor($client, $data);

                return $formalizer->create($client, [
                    'operator_id' => $data['operator_id'] ?? $client->operator_id,
                    'vehicle_id' => $vehicle?->id,
                    'capital' => $data['capital'],
                    'monthly_rate' => $data['monthly_rate'],
                    'administration_fee' => number_format((float) ($data['administration_fee'] ?? 0), 2, '.', ''),
                    'administration_fee_type' => 'monthly',
                    'vat_enabled' => $request->boolean('vat_enabled', true),
                    'interest_calculation_method' => $data['interest_calculation_method'],
                    'term_months' => (int) $data['term_months'],
                    'start_date' => $data['start_date'],
                    'first_payment_date' => $data['first_payment_date'],
                    'payment_day' => (int) $data['payment_day'],
                    'guarantor_name' => $data['guarantor_name'] ?? null,
                    'guarantor_address' => $data['guarantor_address'] ?? null,
                    'guarantor_phone' => $data['guarantor_phone'] ?? null,
                    'delinquency_rate' => number_format((float) ($data['delinquency_rate'] ?? 0), 4, '.', ''),
                    'delinquency_grace_days' => (int) ($data['delinquency_grace_days'] ?? 0),
                    'investors' => $data['investors'] ?? [],
                    'created_by' => $request->user()->id,
                    'status' => 'active',
                ]);
            });

            $this->recordFundDisbursement($loan, $data, $request->user()->id);
            $this->attachTempInvoice($loan, $data, $request->user()->id);

            return redirect()->route('loans.show', $loan)->with('status', 'Prestamo creado con calendario e inversionistas.');
        }

        $quote = $calculator->quote([
            'capital' => $data['capital'],
            'monthly_rate' => $data['monthly_rate'],
            'collection_fee' => $data['administration_fee'] ?? '0.00',
            'term_months' => (int) $data['term_months'],
            'first_payment_date' => $data['first_payment_date'],
        ]);
        $option = $quote['options'][$data['selected_option']];

        if ($allocator->hasParticipants($data['investors'] ?? [])) {
            $allocator->participants($data['investors'], $quote['input']['capital_cents'], allowEmpty: true);
        }

        $loan = DB::transaction(function () use ($request, $data, $quote, $option, $allocator) {
            $client = $this->clientFor($data);
            $vehicle = app(LoanFormalizer::class)->vehicleFor($client, $data);

            $loan = Loan::query()->create([
                'public_id' => (string) Str::ulid(),
                'folio' => LoanFolios::next($data['operator_id'] ?? $client->operator_id),
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
                'first_payment_date' => $data['first_payment_date'],
                'payment_day' => (int) $data['payment_day'],
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_address' => $data['guarantor_address'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'delinquency_rate' => number_format((float) ($data['delinquency_rate'] ?? 0), 4, '.', ''),
                'delinquency_grace_days' => (int) ($data['delinquency_grace_days'] ?? 0),
                'status' => 'active',
            ]);

            foreach ($option['installments'] as $installment) {
                $operationalAmount = $this->operationalAmount($installment);

                $loan->installments()->create([
                    'number' => $installment['number'],
                    'due_date' => $installment['due_date'],
                    'contract_amount' => $installment['amount'],
                    'principal_amount' => $installment['principal'],
                    'administration_fee_amount' => $installment['administration_fee'],
                    'interest_amount' => $installment['interest'],
                    'interest_vat_amount' => '0.00',
                    'capital_balance' => $installment['balance'],
                    'remaining_amount' => $operationalAmount,
                    'status' => 'upcoming',
                ]);
            }

            if ($allocator->hasParticipants($data['investors'] ?? [])) {
                $allocator->assignFromInput($loan, $data['investors'], $request->user()->id);
            }
            $this->recordFundDisbursement($loan, $data, $request->user()->id);

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'rounded_loan_confirmed',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'after' => $loan->quote_snapshot,
            ]);

            return $loan;
        });

        $this->attachTempInvoice($loan, $data, $request->user()->id);

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo con redondeo creado con la opcion seleccionada.');
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    /**
     * @param  array<string, mixed>  $installment
     */
    private function operationalAmount(array $installment): string
    {
        $principal = (int) round(((float) ($installment['principal'] ?? 0)) * 100);
        $interest = (int) round(((float) ($installment['interest'] ?? 0)) * 100);

        return Money::decimal($principal + $interest);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function captureTempInvoice(Request $request, array &$data): void
    {
        if (! $request->hasFile('invoice_file')) {
            return;
        }

        $file = $request->file('invoice_file');
        $path = $file->store('tmp/loan-invoices', 'local');

        $data['invoice_temp_path'] = $path;
        $data['invoice_original_name'] = $file->getClientOriginalName();
        $data['invoice_mime_type'] = $file->getMimeType() ?? 'application/pdf';
        $data['invoice_size'] = $file->getSize();
        $data['invoice_holder'] = $data['invoice_holder'] ?? 'Recepcion';
        unset($data['invoice_file']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attachTempInvoice(Loan $loan, array $data, int $userId): void
    {
        $tempPath = (string) ($data['invoice_temp_path'] ?? '');

        if ($tempPath === '' || ! str_starts_with($tempPath, 'tmp/loan-invoices/') || ! Storage::disk('local')->exists($tempPath)) {
            return;
        }

        $destination = 'expedientes/'.$loan->public_id.'/'.(string) Str::ulid().'.pdf';
        Storage::disk('local')->move($tempPath, $destination);

        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'uploaded_by' => $userId,
            'original_name' => $data['invoice_original_name'] ?? 'Factura del vehiculo.pdf',
            'disk' => 'local',
            'path' => $destination,
            'mime_type' => $data['invoice_mime_type'] ?? 'application/pdf',
            'size' => (int) ($data['invoice_size'] ?? 0),
            'status' => 'delivered',
            'notes' => '[Factura]',
        ]);

        $holder = $data['invoice_holder'] ?? 'Recepcion';
        $loan->update([
            'invoice_document_id' => $document->id,
            'invoice_holder' => $holder,
        ]);

        LoanInvoiceMovement::query()->create([
            'loan_id' => $loan->id,
            'document_id' => $document->id,
            'from_holder' => null,
            'to_holder' => $holder,
            'moved_by' => $userId,
            'moved_at' => now('America/Merida'),
            'notes' => 'Factura cargada al crear prestamo',
        ]);
    }

    private function attachUploadedInvoice(Loan $loan, Request $request, string $holder): void
    {
        $file = $request->file('invoice_file');
        $path = $file->store('expedientes/'.$loan->public_id, 'local');

        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/pdf',
            'size' => $file->getSize(),
            'status' => 'delivered',
            'notes' => '[Factura]',
        ]);

        $loan->update([
            'invoice_document_id' => $document->id,
            'invoice_holder' => $holder,
        ]);

        LoanInvoiceMovement::query()->create([
            'loan_id' => $loan->id,
            'document_id' => $document->id,
            'from_holder' => null,
            'to_holder' => $holder,
            'moved_by' => $request->user()->id,
            'moved_at' => now('America/Merida'),
            'notes' => 'Factura cargada al crear prestamo',
        ]);
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
            'weekly_cut_id' => ['nullable', 'exists:weekly_cuts,id'],
            'disbursement_delivered_on' => ['nullable', 'date'],
            'disbursement_notes' => ['nullable', 'string', 'max:500'],
            'payment_day' => ['required', 'integer', 'min:1', 'max:31'],
            'guarantor_name' => ['nullable', 'string', 'max:180'],
            'guarantor_address' => ['nullable', 'string', 'max:1000'],
            'guarantor_phone' => ['nullable', 'string', 'max:40'],
            'delinquency_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delinquency_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'plates' => ['nullable', 'string', 'max:40'],
            'vin' => ['nullable', 'string', 'size:17'],
            'calculation_method' => ['required', 'in:regular,rounded'],
            'vat_enabled' => ['nullable', 'boolean'],
            'interest_calculation_method' => ['required', 'in:fixed_principal,outstanding_balance'],
            'invoice_file' => ['nullable', 'file', 'mimes:pdf', 'max:102400'],
            'invoice_holder' => ['nullable', 'in:Caja,Recepcion,Operador'],
            'invoice_temp_path' => ['nullable', 'string', 'max:500'],
            'invoice_original_name' => ['nullable', 'string', 'max:255'],
            'invoice_mime_type' => ['nullable', 'string', 'max:120'],
            'invoice_size' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{input:array<string, mixed>, options:array<string, array<string, mixed>>}
     */
    private function regularQuote(array $data, LoanScheduleCalculator $calculator): array
    {
        $schedule = $calculator->calculate([
            'capital' => $data['capital'],
            'monthly_rate' => $data['monthly_rate'],
            'administration_fee' => $data['administration_fee'] ?? '0.00',
            'vat_enabled' => $data['vat_enabled'] ?? true,
            'interest_calculation_method' => $data['interest_calculation_method'] ?? 'fixed_principal',
            'term_months' => (int) $data['term_months'],
            'start_date' => $data['start_date'],
            'first_payment_date' => $data['first_payment_date'],
            'payment_day' => (int) $data['payment_day'],
            'rounding_increment' => 10,
            'rounding_adjustment' => 'first',
        ]);

        $collectionTotalCents = (int) collect($schedule->installments)->sum('administration_fee_cents');
        $interestTotalCents = (int) collect($schedule->installments)->sum('interest_cents');
        $interestVatTotalCents = (int) collect($schedule->installments)->sum('interest_vat_cents');

        return [
            'input' => [
                'capital_cents' => $schedule->capitalCents,
                'monthly_rate' => $data['monthly_rate'],
                'term_months' => (int) $data['term_months'],
                'collection_fee_cents' => Money::cents($data['administration_fee'] ?? 0),
                'interest_monthly_cents' => (int) round($interestTotalCents / max(1, (int) $data['term_months'])),
                'interest_total_cents' => $interestTotalCents + $interestVatTotalCents,
                'collection_total_cents' => $collectionTotalCents,
                'total_cents' => $schedule->contractTotalCents,
                'first_payment_date' => $data['first_payment_date'],
            ],
            'options' => [
                'regular' => [
                    'key' => 'regular',
                    'name' => 'Opcion regular',
                    'description' => 'Calendario regular segun condiciones capturadas',
                    'rounding_multiple' => null,
                    'first_payment' => $schedule->installments[0]['amount'] ?? '0.00',
                    'regular_payment' => $schedule->baseInstallment(),
                    'remaining_payments' => max(0, ((int) $data['term_months']) - 1),
                    'total' => $schedule->contractTotal(),
                    'installments' => collect($schedule->installments)->map(function (array $installment, int $index) use ($schedule) {
                        return $installment + [
                            'previous_balance' => $index === 0
                                ? Money::decimal($schedule->capitalCents)
                                : ($schedule->installments[$index - 1]['balance'] ?? '0.00'),
                        ];
                    })->all(),
                ],
            ],
        ];
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
     * @param  array<string, mixed>  $data
     */
    private function recordFundDisbursement(Loan $loan, array $data, int $userId): void
    {
        $hasExplicitDeliveryDate = filled($data['disbursement_delivered_on'] ?? null);

        if (! $hasExplicitDeliveryDate && empty($data['weekly_cut_id'])) {
            return;
        }

        $weeklyCut = empty($data['weekly_cut_id'])
            ? null
            : WeeklyCut::query()->whereKey($data['weekly_cut_id'])->lockForUpdate()->firstOrFail();

        if ($weeklyCut) {
            abort_if($weeklyCut->operator_id !== $loan->operator_id, 422, 'El corte seleccionado no pertenece al operador del prestamo.');
            abort_if($weeklyCut->status === 'closed', 422, 'No puedes registrar desembolsos en un corte cerrado.');
        }

        $deliveredOn = $hasExplicitDeliveryDate
            ? CarbonImmutable::parse($data['disbursement_delivered_on'], WeeklyCutPeriodService::TIMEZONE)->toDateString()
            : $loan->start_date->toDateString();
        $amountCents = Money::cents($loan->capital);
        $idempotencyKey = sha1('loan-disbursement|'.$loan->id.'|'.($weeklyCut?->id ?? 'outside').'|'.$amountCents);

        $disbursement = FundDisbursement::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'public_id' => (string) Str::ulid(),
                'loan_id' => $loan->id,
                'operator_id' => $loan->operator_id,
                'weekly_cut_id' => $weeklyCut?->id,
                'client_id' => $loan->client_id,
                'vehicle_id' => $loan->vehicle_id,
                'registered_by' => $userId,
                'amount' => Money::decimal($amountCents),
                'delivered_on' => $deliveredOn,
                'registered_at' => now(WeeklyCutPeriodService::TIMEZONE),
                'capital_source' => $loan->investments()->exists() ? 'inversionistas' : 'capital_operativo',
                'notes' => $data['disbursement_notes'] ?? null,
                'status' => 'registered',
                'is_delivery_date_inferred' => ! $hasExplicitDeliveryDate,
            ],
        );

        if ($disbursement->wasRecentlyCreated) {
            $balanceBeforeCents = Money::cents(OperatorLedgerEntry::query()
                ->where('operator_id', $loan->operator_id)
                ->latest('id')
                ->value('balance_after'));
            $balanceAfterCents = $balanceBeforeCents - $amountCents;

            OperatorLedgerEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $loan->operator_id,
                'weekly_cut_id' => $weeklyCut?->id,
                'created_by' => $userId,
                'type' => 'funds_delivered',
                'amount' => Money::decimal($amountCents),
                'balance_before' => Money::decimal($balanceBeforeCents),
                'balance_after' => Money::decimal($balanceAfterCents),
                'idempotency_key' => 'funds-delivered|'.$disbursement->id,
                'reason' => 'Entrega de fondos para prestamo '.$loan->folio,
            ]);

            AuditEvent::query()->create([
                'user_id' => $userId,
                'action' => $weeklyCut ? 'fund_disbursement.created_from_cut' : 'fund_disbursement.created_outside_cut',
                'auditable_type' => FundDisbursement::class,
                'auditable_id' => $disbursement->id,
                'after' => [
                    'loan_id' => $loan->id,
                    'operator_id' => $loan->operator_id,
                    'weekly_cut_id' => $weeklyCut?->id,
                    'amount' => Money::decimal($amountCents),
                    'delivered_on' => $deliveredOn,
                    'is_delivery_date_inferred' => ! $hasExplicitDeliveryDate,
                ],
            ]);
        }

        if ($weeklyCut) {
            app(WeeklyCutPeriodService::class)->refreshTotals($weeklyCut);
        }
    }

    /**
     * @return list<int>
     */
    private function roundedTerms(): array
    {
        return [3, 6, 8, 10, 12, 18, 20, 24, 30, 36, 40, 48, 50, 70];
    }
}
