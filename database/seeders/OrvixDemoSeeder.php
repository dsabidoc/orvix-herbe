<?php

namespace Database\Seeders;

use App\Domain\Loans\LoanScheduleCalculator;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrvixDemoSeeder extends Seeder
{
    private const PASSWORD = 'orvix-demo';

    public function run(): void
    {
        $admin = $this->user('Dueño Orvix', 'admin@orvix.test', 'administrador-general', '9991000001');
        $adriana = $this->user('Adriana Documental', 'adriana@orvix.test', 'responsable-documental', '9991000002');

        $samuelUser = $this->user('Samuel Operador', 'samuel@orvix.test', 'operador-cartera', '9992000001');
        $darioUser = $this->user('Dario Operador', 'dario@orvix.test', 'operador-cartera', '9992000002');
        $santiagoUser = $this->user('Santiago Operador', 'santiago@orvix.test', 'operador-cartera', '9992000003');

        $samuel = $this->operator($samuelUser, [
            'name' => 'Samuel',
            'cut_day' => 5,
            'covers_installment_when_client_misses' => true,
            'allows_shortfalls' => true,
            'alert_rules' => ['whatsapp_reminders' => true, 'ordered_lists' => true],
        ]);

        $dario = $this->operator($darioUser, [
            'name' => 'Dario',
            'cut_day' => 5,
            'max_overdue_installments' => 2,
            'allows_shortfalls' => false,
            'alert_rules' => ['no_accumulate_three_installments' => true, 'monthly_cut_required' => true],
        ]);

        $santiago = $this->operator($santiagoUser, [
            'name' => 'Santiago',
            'cut_day' => 5,
            'max_overdue_installments' => 1,
        ]);

        $directo = $this->operator($admin, [
            'name' => 'Cartera directa oficina',
            'cut_day' => 5,
            'allows_shortfalls' => false,
        ]);

        $loans = [
            $this->loan($samuel, 'Alberto Canto Pech', '9991110101', 'Ignis', 'Blanco', 2019, 143000, 36, 10, '2026-02-10', 5),
            $this->loan($samuel, 'Mayra Tuz Novelo', '9991110102', 'Vento', 'Gris', 2020, 100000, 24, 1, '2026-01-01', 6),
            $this->loan($samuel, 'Aaron Expander Lopez', '9991110103', 'March', 'Rojo', 2021, 122000, 48, 24, '2025-10-24', 8),
            $this->loan($dario, 'Luis Chan Uc', '9992220101', 'Jetta', 'Negro', 2018, 90000, 36, 24, '2026-03-24', 2),
            $this->loan($dario, 'Natalia Canek Moo', '9992220102', 'Aveo', 'Azul', 2020, 78000, 24, 7, '2026-04-07', 1),
            $this->loan($dario, 'Carlos Balam Pat', '9992220103', 'March', 'Plata', 2019, 68000, 24, 15, '2025-12-15', 7),
            $this->loan($santiago, 'Rosa Itza Poot', '9993330101', 'Vento', 'Blanco', 2017, 55000, 24, 30, '2026-02-28', 4),
            $this->loan($santiago, 'Jose Manuel Couoh', '9993330102', 'Versa', 'Arena', 2021, 112000, 36, 5, '2026-05-05', 1),
            $this->loan($directo, 'Martha Cecilia Hau', '9994440101', 'Kicks', 'Naranja', 2022, 160000, 36, 12, '2026-01-12', 6),
        ];

        $loans[] = $this->loanForClient($samuel, $loans[0]->client, 'Versa', 'Gris', 2023, 95000, 18, 20, '2025-01-20', 18, 'settled', 'pronto_pago_cliente');
        $loans[] = $this->loanForClient($dario, $loans[4]->client, 'Spark', 'Rojo', 2018, 48000, 18, 7, '2025-05-07', 8, 'settled', 'dejo_de_pagar');

        $this->reportedPayment($loans[0], $samuelUser, '2026-07-27', $loans[0]->installments()->where('number', 6)->value('contract_amount'), 300, 50, 'Pago semanal Samuel', installmentNumber: 6);
        $this->reportedPayment($loans[1], $samuelUser, '2026-07-28', $loans[1]->installments()->where('number', 7)->value('contract_amount'), 0, 50, 'Vento dia 1 reportado', installmentNumber: 7);
        $this->reportedPayment($loans[3], $darioUser, '2026-07-25', $loans[3]->installments()->where('number', 3)->value('contract_amount'), 300, 0, 'Pendiente de semana anterior Dario', installmentNumber: 3);
        $this->reportedPayment($loans[5], $darioUser, '2026-07-29', (string) (Money::cents($loans[5]->installments()->where('number', 24)->value('contract_amount')) / 100), 0, 0, 'Adelanto desde ultima letra', 'advance', 24);
        $this->reportedPayment($loans[8], $admin, '2026-07-29', $loans[8]->installments()->where('number', 7)->value('contract_amount'), 0, 0, 'Pago oficina directo', installmentNumber: 7);

        $this->weeklyCut($samuel, $samuelUser, '2026-07-24', '2026-07-30', 'submitted');
        $this->weeklyCut($dario, $darioUser, '2026-07-24', '2026-07-30', 'submitted', receivedOverride: 10000);

        $this->documents($loans, $adriana);

        DB::table('audit_events')->insert([
            'user_id' => $admin->id,
            'action' => 'demo.seeded',
            'auditable_type' => 'system',
            'auditable_id' => null,
            'after' => json_encode(['password' => self::PASSWORD, 'loans' => count($loans)]),
            'created_at' => now('America/Merida'),
            'updated_at' => now('America/Merida'),
        ]);
    }

    private function user(string $name, string $email, string $role, string $phone): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'password' => Hash::make(self::PASSWORD),
                'status' => 'active',
                'email_verified_at' => now('America/Merida'),
            ],
        );

        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function operator(User $user, array $overrides): Operator
    {
        return Operator::query()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge([
                'public_id' => (string) Str::ulid(),
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'cut_day' => 5,
                'tolerance_days' => 0,
                'max_overdue_installments' => 0,
                'allows_shortfalls' => false,
                'assumes_collection_risk' => false,
                'covers_installment_when_client_misses' => false,
                'status' => 'active',
            ], $overrides),
        );
    }

    private function loan(Operator $operator, string $clientName, string $phone, string $model, string $color, int $year, int $capital, int $months, int $paymentDay, string $startDate, int $paidInstallments): Loan
    {
        [$firstName, $lastName] = array_pad(explode(' ', $clientName, 2), 2, '');

        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'operator_id' => $operator->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'address' => ['ciudad' => 'Merida', 'estado' => 'Yucatan'],
            'identification_type' => 'INE',
            'identification_last4' => (string) random_int(1000, 9999),
            'manual_score' => $paidInstallments >= 5 ? 85 : 58,
            'calculated_score' => $paidInstallments >= 5 ? 82 : 55,
            'status' => $paidInstallments >= 3 ? 'good_payer' : 'watchlist',
        ]);

        $vehicle = Vehicle::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'brand' => in_array($model, ['Ignis', 'March', 'Kicks', 'Versa'], true) ? 'Nissan/Suzuki' : 'Volkswagen/Chevrolet',
            'model' => $model,
            'year' => $year,
            'color' => $color,
            'vin' => strtoupper(Str::random(17)),
            'plates' => strtoupper(Str::random(3)).'-'.random_int(100, 999).'-Y',
            'price' => Money::decimal($capital * 100 + 2500000),
            'gps_status' => 'instalado',
            'invoice_data' => ['resguardo' => 'Instaka/Dropbox'],
            'status' => 'financed',
        ]);

        $schedule = app(LoanScheduleCalculator::class)->calculate([
            'capital' => (string) $capital,
            'monthly_rate' => '0.02',
            'term_months' => $months,
            'start_date' => $startDate,
            'payment_day' => $paymentDay,
            'rounding_increment' => 10,
            'rounding_adjustment' => 'first',
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'ORV-'.now('America/Merida')->format('y').'-'.str_pad((string) (Loan::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'client_id' => $client->id,
            'operator_id' => $operator->id,
            'vehicle_id' => $vehicle->id,
            'capital' => $schedule->capital(),
            'monthly_rate' => '0.020000',
            'term_months' => $months,
            'contract_total' => $schedule->contractTotal(),
            'start_date' => CarbonImmutable::parse($startDate)->toDateString(),
            'payment_day' => $paymentDay,
            'status' => 'active',
        ]);

        $termVersionId = DB::table('loan_terms_versions')->insertGetId([
            'loan_id' => $loan->id,
            'version' => 1,
            'capital' => $schedule->capital(),
            'monthly_rate' => '0.020000',
            'term_months' => $months,
            'contract_total' => $schedule->contractTotal(),
            'schedule_snapshot' => json_encode($schedule->installments),
            'reason' => 'Condiciones iniciales demo',
            'created_at' => now('America/Merida'),
            'updated_at' => now('America/Merida'),
        ]);

        foreach ($schedule->installments as $installment) {
            $covered = $installment['number'] <= $paidInstallments;
            Installment::query()->create([
                'loan_id' => $loan->id,
                'term_version_id' => $termVersionId,
                'number' => $installment['number'],
                'due_date' => $installment['due_date'],
                'contract_amount' => $installment['amount'],
                'principal_amount' => $installment['principal'] ?? '0.00',
                'interest_amount' => $installment['interest'] ?? '0.00',
                'interest_vat_amount' => $installment['interest_vat'] ?? '0.00',
                'capital_balance' => $installment['balance'] ?? '0.00',
                'applied_amount' => $covered ? $installment['amount'] : '0.00',
                'remaining_amount' => $covered ? '0.00' : $installment['amount'],
                'status' => $covered ? 'confirmed' : 'upcoming',
            ]);

            DB::table('promissory_notes')->insert([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $loan->id,
                'installment_id' => Installment::query()->where('loan_id', $loan->id)->where('number', $installment['number'])->value('id'),
                'number' => $loan->folio.'-'.$installment['number'],
                'physical_location' => $covered ? 'Entregado a operador' : 'Caja Adriana',
                'status' => $covered ? 'delivered_to_operator' : 'in_custody',
                'custodian' => $covered ? $operator->name : 'Adriana',
                'created_at' => now('America/Merida'),
                'updated_at' => now('America/Merida'),
            ]);
        }

        return $loan->fresh(['installments', 'client', 'operator', 'vehicle']);
    }

    private function loanForClient(Operator $operator, Client $client, string $model, string $color, int $year, int $capital, int $months, int $paymentDay, string $startDate, int $paidInstallments, string $status = 'active', ?string $settlementReason = null): Loan
    {
        $vehicle = Vehicle::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'brand' => in_array($model, ['Versa'], true) ? 'Nissan' : 'Chevrolet',
            'model' => $model,
            'year' => $year,
            'color' => $color,
            'vin' => strtoupper(Str::random(17)),
            'plates' => strtoupper(Str::random(3)).'-'.random_int(100, 999).'-Y',
            'price' => Money::decimal($capital * 100 + 1500000),
            'gps_status' => 'instalado',
            'status' => 'financed',
        ]);

        $schedule = app(LoanScheduleCalculator::class)->calculate([
            'capital' => (string) $capital,
            'monthly_rate' => '0.020000',
            'term_months' => $months,
            'start_date' => $startDate,
            'payment_day' => $paymentDay,
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'ORV-'.now('America/Merida')->format('y').'-'.str_pad((string) (Loan::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'client_id' => $client->id,
            'operator_id' => $operator->id,
            'vehicle_id' => $vehicle->id,
            'capital' => $schedule->capital(),
            'monthly_rate' => '0.020000',
            'term_months' => $months,
            'contract_total' => $schedule->contractTotal(),
            'start_date' => CarbonImmutable::parse($startDate)->toDateString(),
            'payment_day' => $paymentDay,
            'status' => $status,
            'settlement_reason' => $settlementReason,
            'settled_at' => $settlementReason ? CarbonImmutable::parse($startDate)->addMonths($paidInstallments)->endOfDay() : null,
        ]);

        foreach ($schedule->installments as $installment) {
            $covered = $status === 'settled' || $installment['number'] <= $paidInstallments;
            Installment::query()->create([
                'loan_id' => $loan->id,
                'number' => $installment['number'],
                'due_date' => $installment['due_date'],
                'contract_amount' => $installment['amount'],
                'principal_amount' => $installment['principal'] ?? '0.00',
                'interest_amount' => $installment['interest'] ?? '0.00',
                'interest_vat_amount' => $installment['interest_vat'] ?? '0.00',
                'capital_balance' => $installment['balance'] ?? '0.00',
                'applied_amount' => $covered ? $installment['amount'] : '0.00',
                'remaining_amount' => $covered ? '0.00' : $installment['amount'],
                'status' => $covered ? 'confirmed' : 'upcoming',
            ]);
        }

        return $loan->fresh(['installments', 'client', 'operator', 'vehicle']);
    }

    private function reportedPayment(Loan $loan, User $registeredBy, string $date, string $amount, int $surcharge, int $external, string $notes, string $type = 'ordinary', ?int $installmentNumber = null): void
    {
        $targetInstallmentId = $installmentNumber
            ? $loan->installments()->where('number', $installmentNumber)->value('id')
            : null;

        CollectionMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'MOV-'.now('America/Merida')->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'idempotency_key' => sha1($loan->id.'|'.$date.'|'.$amount.'|'.$type.'|'.$notes),
            'loan_id' => $loan->id,
            'target_installment_id' => $targetInstallmentId,
            'operator_id' => $loan->operator_id,
            'registered_by' => $registeredBy->id,
            'operated_on' => $date,
            'contract_amount' => $amount,
            'operator_surcharge_amount' => Money::decimal($surcharge * 100),
            'external_concepts_amount' => Money::decimal($external * 100),
            'type' => $type,
            'payment_method' => 'cash',
            'reference' => null,
            'notes' => $notes,
            'confirmation_status' => 'reported',
        ]);
    }

    private function weeklyCut(Operator $operator, User $submittedBy, string $startsOn, string $endsOn, string $status, ?int $receivedOverride = null): void
    {
        $movements = CollectionMovement::query()
            ->where('operator_id', $operator->id)
            ->whereBetween('operated_on', [$startsOn, $endsOn])
            ->where('confirmation_status', 'reported')
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $reportedCents = $movements->sum(fn (CollectionMovement $movement) => Money::cents($movement->contract_amount));
        $receivedCents = $receivedOverride === null ? 0 : $receivedOverride * 100;

        $cut = WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(),
            'operator_id' => $operator->id,
            'submitted_by' => $submittedBy->id,
            'period_starts_on' => $startsOn,
            'period_ends_on' => $endsOn,
            'expected_total' => Money::decimal($reportedCents),
            'reported_total' => Money::decimal($reportedCents),
            'received_total' => Money::decimal($receivedCents),
            'difference_total' => Money::decimal($receivedCents - $reportedCents),
            'previous_balance' => '0.00',
            'regularization_total' => '0.00',
            'accumulated_balance' => Money::decimal($receivedCents - $reportedCents),
            'status' => $status,
            'submitted_at' => now('America/Merida')->subDay(),
        ]);

        foreach ($movements as $movement) {
            DB::table('weekly_cut_items')->insert([
                'weekly_cut_id' => $cut->id,
                'collection_movement_id' => $movement->id,
                'expected_amount' => $movement->contract_amount,
                'reported_amount' => $movement->contract_amount,
                'received_amount' => '0.00',
                'status' => 'included',
                'created_at' => now('America/Merida'),
                'updated_at' => now('America/Merida'),
            ]);
        }
    }

    /**
     * @param  list<Loan>  $loans
     */
    private function documents(array $loans, User $adriana): void
    {
        foreach (['INE', 'Contrato', 'Factura y reverso', 'Tenencias', 'Tarjeta de circulacion', 'GPS', 'Pagares'] as $index => $name) {
            DB::table('document_requirements')->insert([
                'name' => $name,
                'loan_type' => 'vehicle',
                'is_required' => true,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => now('America/Merida'),
                'updated_at' => now('America/Merida'),
            ]);
        }

        $requirementIds = DB::table('document_requirements')->pluck('id', 'name');

        foreach (array_slice($loans, 0, 5) as $loan) {
            foreach (['Contrato', 'Factura y reverso', 'GPS'] as $name) {
                $path = 'demo/'.$loan->folio.'/'.Str::slug($name).'.pdf';
                Storage::disk('local')->put($path, "Documento demo {$name} {$loan->folio}");

                DB::table('documents')->insert([
                    'public_id' => (string) Str::ulid(),
                    'loan_id' => $loan->id,
                    'client_id' => $loan->client_id,
                    'document_requirement_id' => $requirementIds[$name],
                    'uploaded_by' => $adriana->id,
                    'original_name' => Str::slug($loan->folio.' '.$name).'.pdf',
                    'disk' => 'local',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'size' => random_int(120000, 820000),
                    'version' => 1,
                    'status' => 'delivered',
                    'created_at' => now('America/Merida'),
                    'updated_at' => now('America/Merida'),
                ]);
            }
        }

    }
}
