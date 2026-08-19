<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanDeletionService;
use App\Domain\Loans\InterestOnlyScheduleExtender;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Domain\Loans\LoanSettlementService;
use App\Models\Client;
use App\Models\Document;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Loan;
use App\Models\LoanInvoiceMovement;
use App\Models\LoanNote;
use App\Models\Operator;
use App\Models\Vehicle;
use App\Support\InvoiceHolders;
use App\Support\LoanFolios;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

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
        ]);
    }

    public function show(Request $request, Loan $loan, LoanSettlementService $settlementService, InterestOnlyScheduleExtender $interestOnlyScheduleExtender): View
    {
        $this->authorizeLoanAccess($request, $loan);

        $interestOnlyScheduleExtender->ensureCoverage($loan);

        $loan = $loan->load([
            'client',
            'operator',
            'vehicle',
            'documents',
            'invoiceDocument',
            'notes.user',
            'invoiceMovements.movedBy',
            'invoiceMovements.document',
            'investments.investor',
            'fundDisbursements.weeklyCut',
            'fundDisbursements.operator',
            'fundDisbursements.registeredBy',
            'installments' => fn ($query) => $query->with('reportedMovement')->orderBy('number'),
            'movements' => fn ($query) => $query->with(['registeredBy', 'allocations.installment'])->latest(),
        ]);

        $fundingInvestors = Investor::availableForFunding()
            ->get()
            ->merge($loan->investments->pluck('investor')->filter())
            ->unique('id')
            ->sortBy('name')
            ->values();

        return view('loans.show', [
            'loan' => $loan,
            'investors' => $fundingInvestors,
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
            'guarantors' => $this->guarantorOptions(),
            'canEditConditions' => $this->canEditConditions($loan),
        ]);
    }

    public function storeNote(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageLoanDetails($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        LoanNote::query()->create([
            'loan_id' => $loan->id,
            'user_id' => $request->user()->id,
            'note' => $data['note'],
        ]);

        return back()->with('status', 'Nota agregada al prestamo.');
    }

    public function freeze(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageLoanDetails($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'frozen_reason' => ['required', 'string', 'max:1000'],
        ]);

        $loan->update([
            'is_frozen' => true,
            'frozen_reason' => $data['frozen_reason'],
            'frozen_at' => now('America/Merida'),
            'frozen_by' => $request->user()->id,
        ]);

        LoanNote::query()->create([
            'loan_id' => $loan->id,
            'user_id' => $request->user()->id,
            'note' => 'Prestamo congelado: '.$data['frozen_reason'],
        ]);

        return back()->with('status', 'Prestamo congelado y fuera de cobranza esperada.');
    }

    public function unfreeze(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageLoanDetails($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $loan->update([
            'is_frozen' => false,
            'frozen_reason' => null,
            'frozen_at' => null,
            'frozen_by' => null,
        ]);

        LoanNote::query()->create([
            'loan_id' => $loan->id,
            'user_id' => $request->user()->id,
            'note' => 'Prestamo reactivado para cobranza.',
        ]);

        return back()->with('status', 'Prestamo reactivado en cobranza.');
    }

    public function storeInvoice(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageInvoice($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:102400'],
        ]);

        $file = $request->file('file');
        $path = $file->store('expedientes/'.$loan->public_id, 'local');
        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'uploaded_by' => $request->user()->id,
            'original_name' => 'Factura del vehiculo.'.$file->getClientOriginalExtension(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/pdf',
            'size' => $file->getSize(),
            'status' => 'delivered',
            'notes' => filled($data['notes'] ?? null) ? '[Factura] '.$data['notes'] : '[Factura]',
        ]);
        $loan->update([
            'invoice_document_id' => $document->id,
        ]);

        return back()->with('status', 'Factura cargada. La ubicacion fisica no cambio.');
    }

    public function moveInvoice(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageInvoice($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'to_holder' => ['required', 'in:'.implode(',', InvoiceHolders::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $from = $loan->invoice_holder;
        $loan->update(['invoice_holder' => $data['to_holder']]);

        LoanInvoiceMovement::query()->create([
            'loan_id' => $loan->id,
            'document_id' => $loan->invoice_document_id,
            'from_holder' => $from,
            'to_holder' => $data['to_holder'],
            'moved_by' => $request->user()->id,
            'moved_at' => now('America/Merida'),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Ubicacion fisica de factura actualizada.');
    }

    public function destroyInvoice(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->canManageInvoice($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        $document = $loan->invoiceDocument;
        $previousHolder = $loan->invoice_holder;

        if (! $document) {
            $loan->update([
                'invoice_document_id' => null,
                'invoice_holder' => null,
            ]);

            return back()->with('status', 'La factura quedo lista para cargar un nuevo archivo.');
        }

        DB::transaction(function () use ($document, $loan, $previousHolder, $request): void {
            $loan->update([
                'invoice_document_id' => null,
                'invoice_holder' => null,
            ]);

            LoanInvoiceMovement::query()->create([
                'loan_id' => $loan->id,
                'document_id' => $document->id,
                'from_holder' => $previousHolder,
                'to_holder' => 'Eliminada',
                'moved_by' => $request->user()->id,
                'moved_at' => now('America/Merida'),
                'notes' => 'Factura PDF eliminada para reemplazo.',
            ]);

            if ($document->disk && $document->path) {
                Storage::disk($document->disk)->delete($document->path);
            }

            $document->delete();
        });

        return back()->with('status', 'Factura eliminada. Ya puedes cargar un nuevo archivo.');
    }

    public function update(Request $request, Loan $loan, LoanScheduleCalculator $calculator): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize'), 403);
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'regex:/^\d{10}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'operator_id' => ['required', 'exists:operators,id'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'plates' => ['nullable', 'string', 'max:40'],
            'vin' => ['nullable', 'string', 'size:17'],
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
            'guarantor_name' => ['nullable', 'string', 'max:180'],
            'guarantor_address' => ['nullable', 'string', 'max:1000'],
            'guarantor_phone' => ['nullable', 'regex:/^\d{10}$/'],
            'delinquency_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delinquency_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'phone.regex' => 'El celular del cliente debe tener exactamente 10 digitos.',
            'guarantor_phone.regex' => 'El celular del aval debe tener exactamente 10 digitos.',
        ]);

        $newMonthlyRate = number_format($this->monthlyRate((float) $data['rate_value'], $data['rate_type']), 6, '.', '');
        $data['administration_fee_type'] = 'monthly';
        $newAdministrationFee = number_format((float) ($data['administration_fee'] ?? 0), 2, '.', '');
        $data['vat_enabled'] = $request->boolean('vat_enabled', true);
        $data['vin'] = $this->normalizeVin($data['vin'] ?? null);
        $this->ensureVinIsAvailable($data['vin'], $loan);
        $financialConditionsChanged = $this->financialConditionsChanged($loan, $data, $newMonthlyRate);
        $dateScheduleChanged = $this->dateScheduleChanged($loan, $data);

        if ($financialConditionsChanged && ! $this->canEditConditions($loan)) {
            return back()
                ->withErrors(['capital' => 'Este prestamo ya tiene cobros registrados; solo puedes editar datos generales y fechas.'])
                ->withInput();
        }

        DB::transaction(function () use ($loan, $data, $newMonthlyRate, $newAdministrationFee, $financialConditionsChanged, $dateScheduleChanged, $calculator) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $folioSourceChanged = (int) $loan->operator_id !== (int) $data['operator_id']
                || $loan->start_date->toDateString() !== CarbonImmutable::parse($data['start_date'])->toDateString();
            $updatedFolio = $folioSourceChanged
                ? LoanFolios::next((int) $data['operator_id'], $data['start_date'], $loan->id)
                : $loan->folio;

            $this->updateClientForLoan($loan, $data);

            $vehicle = $this->updateVehicleForLoan($loan, $data);

            if (! $financialConditionsChanged) {
                $loan->update([
                    'folio' => $updatedFolio,
                    'operator_id' => $data['operator_id'],
                    'vehicle_id' => $vehicle?->id,
                    'start_date' => $data['start_date'],
                    'first_payment_date' => $data['first_payment_date'],
                    'payment_day' => (int) $data['payment_day'],
                    'guarantor_name' => $data['guarantor_name'] ?? null,
                    'guarantor_address' => $data['guarantor_address'] ?? null,
                    'guarantor_phone' => $data['guarantor_phone'] ?? null,
                    'delinquency_rate' => number_format((float) ($data['delinquency_rate'] ?? 0), 4, '.', ''),
                    'delinquency_grace_days' => (int) ($data['delinquency_grace_days'] ?? 0),
                ]);

                if ($dateScheduleChanged) {
                    $this->updateInstallmentDueDates($loan, $data['first_payment_date'], (int) $data['payment_day']);
                }

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
                'folio' => $updatedFolio,
                'operator_id' => $data['operator_id'],
                'vehicle_id' => $vehicle?->id,
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
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_address' => $data['guarantor_address'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'delinquency_rate' => number_format((float) ($data['delinquency_rate'] ?? 0), 4, '.', ''),
                'delinquency_grace_days' => (int) ($data['delinquency_grace_days'] ?? 0),
            ]);

            foreach ($schedule->installments as $installment) {
                $operationalAmount = Money::decimal(
                    Money::cents($installment['principal'] ?? 0) + Money::cents($installment['interest'] ?? 0),
                );

                $loan->installments()->create([
                    'number' => $installment['number'],
                    'due_date' => $installment['due_date'],
                    'contract_amount' => $installment['amount'],
                    'principal_amount' => $installment['principal'] ?? '0.00',
                    'administration_fee_amount' => $installment['administration_fee'] ?? '0.00',
                    'interest_amount' => $installment['interest'] ?? '0.00',
                    'interest_vat_amount' => $installment['interest_vat'] ?? '0.00',
                    'capital_balance' => $installment['balance'] ?? '0.00',
                    'remaining_amount' => $operationalAmount,
                    'status' => 'upcoming',
                ]);
            }

        });

        return redirect()->route('loans.show', $loan)->with('status', 'Prestamo actualizado.');
    }

    public function destroy(Request $request, Loan $loan, LoanDeletionService $deletionService): RedirectResponse
    {
        abort_unless($request->user()->can('loans.formalize') && ! $this->isProviderUser($request), 403);
        $this->authorizeLoanAccess($request, $loan);

        try {
            $deletionService->delete($loan, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['loan' => $exception->getMessage()]);
        }

        return redirect()->route('loans.index')->with('status', 'Prestamo eliminado y saldos relacionados revertidos.');
    }

    private function authorizeLoanAccess(Request $request, Loan $loan): void
    {
        if ($request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage')) {
            abort_unless(
                $request->user()->investorProfile
                    && $loan->investments()->where('investor_id', $request->user()->investorProfile->id)->exists(),
                403
            );

            return;
        }

        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function canEditConditions(Loan $loan): bool
    {
        return ! $loan->installments()->where('applied_amount', '>', 0)->exists()
            && ! $loan->movements()->exists();
    }

    private function canManageLoanDetails(Request $request): bool
    {
        return ! $this->isProviderUser($request)
            && ($request->user()->can('loans.formalize') || $request->user()->can('payments.confirm'));
    }

    private function canManageInvoice(Request $request): bool
    {
        return ! $this->isProviderUser($request)
            && ($request->user()->can('loans.formalize') || $request->user()->can('payments.confirm') || $request->user()->can('documents.manage'));
    }

    private function isProviderUser(Request $request): bool
    {
        return $request->user()->hasRole('operador-cartera') || $request->user()->hasRole('proveedor');
    }

    private function financialConditionsChanged(Loan $loan, array $data, string $newMonthlyRate): bool
    {
        return Money::cents($loan->capital) !== Money::cents($data['capital'])
            || (string) $loan->monthly_rate !== $newMonthlyRate
            || Money::cents($loan->administration_fee ?? 0) !== Money::cents($data['administration_fee'] ?? 0)
            || (bool) $loan->vat_enabled !== (bool) $data['vat_enabled']
            || $loan->interest_calculation_method !== $data['interest_calculation_method']
            || (int) $loan->term_months !== (int) $data['term_months'];
    }

    private function dateScheduleChanged(Loan $loan, array $data): bool
    {
        return (int) $loan->payment_day !== (int) $data['payment_day']
            || $loan->start_date->toDateString() !== CarbonImmutable::parse($data['start_date'])->toDateString()
            || ($loan->first_payment_date ?? $loan->start_date)->toDateString() !== CarbonImmutable::parse($data['first_payment_date'])->toDateString();
    }

    private function updateInstallmentDueDates(Loan $loan, string $firstPaymentDate, int $paymentDay): void
    {
        $firstDate = CarbonImmutable::parse($firstPaymentDate, 'America/Merida');

        $loan->installments()
            ->orderBy('number')
            ->get()
            ->each(function (Installment $installment) use ($firstDate, $paymentDay) {
                $month = $firstDate->startOfMonth()->addMonthsNoOverflow($installment->number - 1);
                $dueDate = $month->day(min($paymentDay, $month->daysInMonth));

                $installment->update(['due_date' => $dueDate->toDateString()]);
            });
    }

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }

    private function ensureVinIsAvailable(?string $vin, Loan $currentLoan): void
    {
        if (! $vin) {
            return;
        }

        $conflict = Loan::query()
            ->where('status', 'active')
            ->whereKeyNot($currentLoan->id)
            ->whereHas('vehicle', fn ($query) => $query->whereRaw('UPPER(vin) = ?', [$vin]))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'vin' => 'Este VIN ya esta ligado a un prestamo activo. Solo se puede reutilizar cuando el prestamo anterior este liquidado.',
            ]);
        }
    }

    private function normalizeVin(mixed $vin): ?string
    {
        $vin = Str::upper(trim((string) $vin));

        return $vin === '' ? null : $vin;
    }

    private function guarantorOptions()
    {
        return Loan::query()
            ->whereNotNull('guarantor_name')
            ->where('guarantor_name', '!=', '')
            ->select('guarantor_name', 'guarantor_address', 'guarantor_phone')
            ->distinct()
            ->orderBy('guarantor_name')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateClientForLoan(Loan $loan, array $data): void
    {
        $payload = [
            'operator_id' => $data['operator_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? null,
        ];

        $client = $loan->client()->lockForUpdate()->firstOrFail();
        $hasSharedLoans = $client->loans()
            ->whereKeyNot($loan->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasSharedLoans || ! $this->clientIdentityChanged($client, $payload)) {
            $client->update($payload);
            $loan->setRelation('client', $client);

            return;
        }

        $newClient = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'status' => 'active',
        ] + $payload);

        $loan->forceFill(['client_id' => $newClient->id])->save();
        $loan->setRelation('client', $newClient);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function clientIdentityChanged(Client $client, array $payload): bool
    {
        foreach (['first_name', 'last_name', 'phone', 'email'] as $field) {
            if ((string) ($client->{$field} ?? '') !== (string) ($payload[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateVehicleForLoan(Loan $loan, array $data): ?Vehicle
    {
        $vehicleData = [
            'client_id' => $loan->client_id,
            'brand' => $data['brand'] ?? 'Sin marca',
            'model' => $data['model'] ?? 'Vehiculo',
            'year' => $data['year'] ?? null,
            'plates' => $data['plates'] ?? null,
            'vin' => $data['vin'] ?? null,
            'status' => 'financed',
        ];

        $existingByVin = $data['vin']
            ? Vehicle::query()->whereRaw('UPPER(vin) = ?', [$data['vin']])->first()
            : null;

        if ($existingByVin && (int) $existingByVin->id !== (int) $loan->vehicle_id) {
            $existingByVin->update($vehicleData);

            return $existingByVin;
        }

        if ($loan->vehicle) {
            $loan->vehicle->update($vehicleData);

            return $loan->vehicle;
        }

        return Vehicle::query()->create($vehicleData + [
            'public_id' => (string) Str::ulid(),
            'client_id' => $loan->client_id,
        ]);
    }
}
