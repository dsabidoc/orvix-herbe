<?php

namespace Database\Seeders;

use App\Domain\Investors\InvestmentAllocationService;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\InvestorWithdrawalRequest;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrvixDemoSeeder extends Seeder
{
    private const PASSWORD = 'orvix-demo';

    public function run(): void
    {
        $this->resetDemoData();

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

        $investors = collect([
            $this->investor('Alejandro', 'Patron', 'alejandro.inversionista@orvix.test', '9995000001', 650000, true),
            $this->investor('Beatriz', 'Camara', 'beatriz.inversionista@orvix.test', '9995000002', 520000, true),
            $this->investor('Carlos', 'Mendez', 'carlos.inversionista@orvix.test', '9995000003', 760000, false),
            $this->investor('Daniela', 'Rosado', 'daniela.inversionista@orvix.test', '9995000004', 430000, false),
            $this->investor('Fernando', 'Aguilar', 'fernando.inversionista@orvix.test', '9995000005', 580000, false),
        ]);

        $loans = [
            $this->loan($samuel, 'Alberto Canto Pech', '9991110101', 'Ignis', 'Blanco', 2019, 143000, 36, 10, '2026-02-10', 6),
            $this->loan($samuel, 'Mayra Tuz Novelo', '9991110102', 'Vento', 'Gris', 2020, 100000, 24, 1, '2026-03-01', 6),
            $this->loan($samuel, 'Aaron Expander Lopez', '9991110103', 'March', 'Rojo', 2021, 122000, 48, 24, '2026-05-24', 3),
            $this->loan($samuel, 'Paloma Medina Puc', '9991110104', 'Aveo', 'Azul', 2020, 20000, 27, 5, '2026-02-05', 6),
            $this->loan($samuel, 'Mar Brito', '9991110105', 'Beat', 'Plata', 2019, 100000, 36, 15, '2026-05-15', 2),
            $this->loan($dario, 'Luis Chan Uc', '9992220101', 'Jetta', 'Negro', 2018, 90000, 36, 24, '2026-03-24', 5),
            $this->loan($dario, 'Natalia Canek Moo', '9992220102', 'Aveo', 'Azul', 2020, 78000, 24, 7, '2026-04-07', 4),
            $this->loan($dario, 'Carlos Balam Pat', '9992220103', 'March', 'Plata', 2019, 68000, 24, 15, '2026-07-15', 0),
            $this->loan($dario, 'Karen Huerta Solis', '9992220104', 'Sentra', 'Blanco', 2021, 150000, 48, 20, '2026-07-20', 0),
            $this->loan($dario, 'Belen Tuz Chi', '9992220105', 'Spark', 'Rojo', 2018, 64000, 24, 3, '2026-03-03', 5),
            $this->loan($santiago, 'Rosa Itza Poot', '9993330101', 'Vento', 'Blanco', 2017, 55000, 24, 30, '2026-06-30', 1),
            $this->loan($santiago, 'Jose Manuel Couoh', '9993330102', 'Versa', 'Arena', 2021, 112000, 36, 5, '2026-08-05', 0),
            $this->loan($santiago, 'Lucia Camara May', '9993330103', 'Kwid', 'Gris', 2022, 72000, 24, 18, '2026-04-18', 4),
            $this->loan($santiago, 'Victor Pech Canul', '9993330104', 'Rio', 'Negro', 2020, 88000, 36, 25, '2026-07-25', 0),
            $this->loan($santiago, 'Octavio Dzul Pool', '9993330105', 'Mazda 2', 'Rojo', 2021, 118000, 36, 11, '2026-06-11', 2),
            $this->loan($directo, 'Martha Cecilia Hau', '9994440101', 'Kicks', 'Naranja', 2022, 160000, 36, 12, '2026-01-12', 7),
            $this->loan($directo, 'Esteban Sabido', '9994440102', 'Civic', 'Azul', 2021, 250000, 48, 10, '2026-08-10', 0),
            $this->loan($directo, 'Leonardo Martinez Dominguez', '9994440103', 'Jetta', 'Gris', 2019, 135000, 36, 30, '2026-06-30', 2),
        ];

        $loans[] = $this->loanForClient($samuel, $loans[0]->client, 'Versa', 'Gris', 2023, 95000, 18, 20, '2025-01-20', 18, 'settled', 'pronto_pago_cliente');
        $loans[] = $this->loanForClient($dario, $loans[4]->client, 'Spark', 'Rojo', 2018, 48000, 18, 7, '2025-05-07', 8, 'settled', 'dejo_de_pagar');

        $this->assignInvestors($loans, $investors, $admin);
        $this->seedInvestorReturns($investors, $admin);

        $this->reportedPayment($loans[0], $samuelUser, '2026-07-27', $loans[0]->installments()->where('number', 6)->value('contract_amount'), 300, 50, 'Pago semanal Samuel', installmentNumber: 6);
        $this->reportedPayment($loans[1], $samuelUser, '2026-07-28', $loans[1]->installments()->where('number', 7)->value('contract_amount'), 0, 50, 'Vento dia 1 reportado', installmentNumber: 7);
        $this->reportedPayment($loans[5], $darioUser, '2026-07-25', $loans[5]->installments()->where('number', 6)->value('contract_amount'), 300, 0, 'Pendiente de semana anterior Dario', installmentNumber: 6);
        $this->reportedPayment($loans[9], $darioUser, '2026-07-29', (string) (Money::cents($loans[9]->installments()->where('number', 24)->value('contract_amount')) / 100), 0, 0, 'Adelanto desde ultima letra', 'advance', 24);
        $this->reportedPayment($loans[15], $admin, '2026-07-29', $loans[15]->installments()->where('number', 7)->value('contract_amount'), 0, 0, 'Pago oficina directo', installmentNumber: 7);

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

    private function resetDemoData(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'investor_withdrawal_requests',
            'investor_capital_movements',
            'investment_ledger_entries',
            'investments',
            'weekly_cut_items',
            'weekly_cuts',
            'operator_ledger_entries',
            'payment_allocations',
            'collection_movements',
            'custody_events',
            'promissory_notes',
            'documents',
            'document_requirements',
            'settlements',
            'settlement_quotes',
            'installments',
            'loan_terms_versions',
            'loans',
            'application_status_history',
            'loan_applications',
            'simulations',
            'vehicles',
            'client_references',
            'clients',
            'operators',
            'investors',
            'audit_events',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
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

    private function investor(string $firstName, string $lastName, string $email, string $phone, int $initialCapital, bool $withUser): Investor
    {
        $user = $withUser
            ? $this->user($firstName.' '.$lastName, $email, 'inversionista', $phone)
            : null;

        $investor = Investor::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user?->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'email' => $email,
            'phone' => $phone,
            'initial_capital' => Money::decimal($initialCapital * 100),
            'available_capital' => Money::decimal($initialCapital * 100),
            'returned_capital_balance' => '0.00',
            'generated_interest_balance' => '0.00',
            'status' => 'active',
        ]);

        InvestorCapitalMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investor->id,
            'created_by' => $user?->id,
            'type' => 'initial_capital',
            'amount' => Money::decimal($initialCapital * 100),
            'balance_before' => '0.00',
            'balance_after' => Money::decimal($initialCapital * 100),
            'notes' => 'Capital inicial demo',
        ]);

        return $investor;
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

    /**
     * @param  list<Loan>  $loans
     */
    private function assignInvestors(array $loans, Collection $investors, User $admin): void
    {
        $allocator = app(InvestmentAllocationService::class);
        $investors = $investors->values();

        foreach ($loans as $index => $loan) {
            $capital = Money::cents($loan->capital) / 100;
            $primary = $investors[$index % $investors->count()];
            $secondary = $investors[($index + 2) % $investors->count()];

            $input = match ($index % 4) {
                0 => [
                    ['investor_id' => $primary->id, 'capital_amount' => number_format($capital * 0.70, 2, '.', ''), 'interest_share_percent' => 70],
                    ['investor_id' => $secondary->id, 'capital_amount' => number_format($capital * 0.30, 2, '.', ''), 'interest_share_percent' => 30],
                ],
                1 => [
                    ['investor_id' => $primary->id, 'capital_amount' => number_format($capital * 0.55, 2, '.', ''), 'interest_share_percent' => 50],
                    ['investor_id' => $secondary->id, 'capital_amount' => number_format($capital * 0.45, 2, '.', ''), 'interest_share_percent' => 50],
                ],
                default => [
                    ['investor_id' => $primary->id, 'capital_amount' => number_format($capital, 2, '.', ''), 'interest_share_percent' => 100],
                ],
            };

            $assignedCents = collect($input)->sum(fn (array $row) => Money::cents($row['capital_amount']));
            $differenceCents = Money::cents($loan->capital) - $assignedCents;

            if ($differenceCents !== 0) {
                $input[0]['capital_amount'] = Money::decimal(Money::cents($input[0]['capital_amount']) + $differenceCents);
            }

            $allocator->assignFromInput($loan, $input, $admin->id);
        }
    }

    private function seedInvestorReturns(Collection $investors, User $admin): void
    {
        $demoReturns = [
            ['returned' => 62000, 'interest' => 18450],
            ['returned' => 38000, 'interest' => 12200],
            ['returned' => 0, 'interest' => 9650],
            ['returned' => 25000, 'interest' => 0],
            ['returned' => 47000, 'interest' => 15800],
        ];

        foreach ($investors->values() as $index => $investor) {
            $returnedCents = $demoReturns[$index]['returned'] * 100;
            $interestCents = $demoReturns[$index]['interest'] * 100;
            $totalCents = $returnedCents + $interestCents;

            $investor->forceFill([
                'returned_capital_balance' => Money::decimal($returnedCents),
                'generated_interest_balance' => Money::decimal($interestCents),
            ])->save();

            if ($totalCents > 0) {
                InvestorCapitalMovement::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'investor_id' => $investor->id,
                    'created_by' => $admin->id,
                    'type' => 'returns_recorded',
                    'amount' => Money::decimal($totalCents),
                    'balance_before' => $investor->available_capital,
                    'balance_after' => $investor->available_capital,
                    'notes' => 'Retornos demo listos para reinvertir',
                    'metadata' => [
                        'returned_capital' => Money::decimal($returnedCents),
                        'generated_interest' => Money::decimal($interestCents),
                    ],
                ]);
            }
        }

        InvestorWithdrawalRequest::query()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investors[1]->id,
            'requested_by' => $investors[1]->user_id,
            'amount' => '15000.00',
            'status' => 'submitted',
            'notes' => 'Solicitud demo para probar aprobacion de retiro.',
        ]);
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
