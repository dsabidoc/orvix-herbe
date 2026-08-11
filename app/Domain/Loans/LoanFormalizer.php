<?php

namespace App\Domain\Loans;

use App\Domain\Investors\InvestmentAllocationService;
use App\Models\Client;
use App\Models\Loan;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanFormalizer
{
    public function __construct(private readonly LoanScheduleCalculator $calculator) {}

    /**
     * @param  array{capital:string,monthly_rate:string,administration_fee?:string,administration_fee_type?:string,vat_enabled?:bool|int|string,term_months:int,start_date:string,first_payment_date?:string,payment_day:int,operator_id:int|null,interest_calculation_method?:string,vehicle_id?:int|null,loan_application_id?:int|null,status?:string}  $data
     */
    public function create(Client $client, array $data): Loan
    {
        return DB::transaction(function () use ($client, $data) {
            $schedule = $this->calculator->calculate([
                'capital' => $data['capital'],
                'monthly_rate' => $data['monthly_rate'],
                'administration_fee' => $data['administration_fee'] ?? '0.00',
                'administration_fee_type' => $data['administration_fee_type'] ?? 'monthly',
                'vat_enabled' => $data['vat_enabled'] ?? true,
                'interest_calculation_method' => $data['interest_calculation_method'] ?? 'fixed_principal',
                'term_months' => (int) $data['term_months'],
                'start_date' => $data['start_date'],
                'first_payment_date' => $data['first_payment_date'] ?? $data['start_date'],
                'payment_day' => (int) $data['payment_day'],
            ]);

            $loan = Loan::query()->create([
                'public_id' => (string) Str::ulid(),
                'folio' => 'ORV-'.now('America/Merida')->format('ymd').'-'.str_pad((string) (Loan::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'client_id' => $client->id,
                'operator_id' => $data['operator_id'] ?? $client->operator_id,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'loan_application_id' => $data['loan_application_id'] ?? null,
                'capital' => $schedule->capital(),
                'monthly_rate' => $data['monthly_rate'],
                'administration_fee' => $data['administration_fee'] ?? '0.00',
                'administration_fee_type' => $data['administration_fee_type'] ?? 'monthly',
                'vat_enabled' => $data['vat_enabled'] ?? true,
                'interest_calculation_method' => $data['interest_calculation_method'] ?? 'fixed_principal',
                'term_months' => (int) $data['term_months'],
                'contract_total' => $schedule->contractTotal(),
                'start_date' => $data['start_date'],
                'first_payment_date' => $data['first_payment_date'] ?? $data['start_date'],
                'payment_day' => (int) $data['payment_day'],
                'status' => $data['status'] ?? 'active',
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

            if (isset($data['investors'])) {
                app(InvestmentAllocationService::class)->assignFromInput($loan, $data['investors'], $data['created_by'] ?? null);
            }

            return $loan;
        });
    }

    public function vehicleFor(Client $client, array $data): Vehicle
    {
        return Vehicle::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'brand' => $data['brand'] ?? 'Sin marca',
            'model' => $data['model'] ?? 'Vehiculo',
            'year' => $data['year'] ?? null,
            'color' => $data['color'] ?? null,
            'vin' => $data['vin'] ?? null,
            'plates' => $data['plates'] ?? null,
            'price' => $data['price'] ?? null,
            'status' => 'financed',
        ]);
    }
}
