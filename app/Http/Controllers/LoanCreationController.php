<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanFormalizer;
use App\Models\Client;
use App\Models\Document;
use App\Models\Operator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private function monthlyRate(float $rateValue, string $rateType): float
    {
        $decimalRate = $rateValue / 100;

        return $rateType === 'annual' ? $decimalRate / 12 : $decimalRate;
    }
}
