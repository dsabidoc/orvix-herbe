<?php

namespace Tests\Unit\Domain;

use App\Domain\Portfolio\PortfolioBalanceService;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PortfolioBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(PortfolioBalanceService::class);
    }

    public function test_due_today_has_zero_late_days_and_is_not_overdue(): void
    {
        [$admin] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-10', 'amount' => '1000.00'],
        ]);

        $row = $this->loanRow($admin, $loan, '2026-08-10');
        $installment = $row['installments']->first();

        $this->assertSame(0, $installment['late_days']);
        $this->assertFalse($installment['is_overdue']);
        $this->assertSame('Vence hoy', $installment['status']['label']);
        $this->assertSame(0, $row['overdue_cents']);
        $this->assertSame(100000, $row['due_today_cents']);
    }

    public function test_yesterday_due_installment_has_one_late_day(): void
    {
        [$admin] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-09', 'amount' => '1000.00'],
        ]);

        $row = $this->loanRow($admin, $loan, '2026-08-10');
        $installment = $row['installments']->first();

        $this->assertSame(1, $installment['late_days']);
        $this->assertTrue($installment['is_overdue']);
        $this->assertSame(100000, $row['overdue_cents']);
    }

    public function test_future_installment_has_zero_late_days(): void
    {
        [$admin] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-15', 'amount' => '1000.00'],
        ]);

        $row = $this->loanRow($admin, $loan, '2026-08-10');
        $installment = $row['installments']->first();

        $this->assertSame(0, $installment['late_days']);
        $this->assertFalse($installment['is_overdue']);
        $this->assertSame('Proximo', $installment['status']['label']);
    }

    public function test_partial_payment_only_leaves_remaining_balance_as_overdue(): void
    {
        [$admin, $operator] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '5000.00'],
        ], $operator);
        $this->applyPayment($loan, 1, '2026-08-05', '2000.00');

        $row = $this->loanRow($admin, $loan, '2026-08-10');
        $installment = $row['installments']->first();

        $this->assertSame(300000, $installment['pending_cents']);
        $this->assertSame(300000, $installment['overdue_cents']);
        $this->assertSame(300000, $row['overdue_cents']);
    }

    public function test_payment_after_historical_cutoff_does_not_reduce_previous_report(): void
    {
        [$admin, $operator] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '5000.00'],
        ], $operator);
        $this->applyPayment($loan, 1, '2026-08-15', '5000.00');

        $beforePayment = $this->loanRow($admin, $loan, '2026-08-10');
        $afterPayment = $this->loanRow($admin, $loan, '2026-08-16');

        $this->assertSame(500000, $beforePayment['overdue_cents']);
        $this->assertSame(0, $afterPayment['pending_cents']);
        $this->assertSame(0, $afterPayment['overdue_cents']);
    }

    public function test_operator_only_receives_own_portfolio_rows(): void
    {
        [, $operator, $operatorUser] = $this->admin();
        $ownLoan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '1000.00'],
        ], $operator);

        $otherUser = $this->user('Otro Operador', 'otro@orvix.test', 'operador-cartera');
        $otherOperator = $this->operator($otherUser, 'Otro');
        $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '9000.00'],
        ], $otherOperator);

        $report = $this->service->build(['cutoff_date' => '2026-08-10'], $operatorUser);

        $this->assertCount(1, $report['loan_rows']);
        $this->assertSame($ownLoan->folio, $report['loan_rows']->first()['folio']);
    }

    public function test_detail_rows_show_each_pending_installment_separately_without_future_months(): void
    {
        [$admin, $operator] = $this->admin();
        $loan = $this->loanWithInstallments([
            ['number' => 5, 'due_date' => '2026-08-01', 'amount' => '1000.00'],
            ['number' => 6, 'due_date' => '2026-08-05', 'amount' => '1200.00'],
            ['number' => 7, 'due_date' => '2026-09-05', 'amount' => '1300.00'],
        ], $operator);

        $report = $this->service->build(['cutoff_date' => '2026-08-10'], $admin);
        $rows = $report['detail_rows']->where('loan_id', $loan->id)->values();

        $this->assertCount(2, $rows);
        $this->assertSame(['5/7', '6/7'], $rows->pluck('payment_progress')->all());
        $this->assertSame([220000, 220000], $rows->pluck('overdue_cents')->all());
    }

    public function test_detail_rows_are_ordered_by_payment_day_then_start_date_oldest_first(): void
    {
        [$admin, $operator] = $this->admin();
        $dayOneNewLoan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '1000.00'],
        ], $operator);
        $dayOneOldLoan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '1000.00'],
        ], $operator);
        $dayTwoOlderLoan = $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-02', 'amount' => '1000.00'],
        ], $operator);

        $dayOneNewLoan->forceFill(['start_date' => '2026-01-10', 'payment_day' => 1, 'folio' => 'NEW-100126-01'])->save();
        $dayOneOldLoan->forceFill(['start_date' => '2024-01-10', 'payment_day' => 1, 'folio' => 'OLD-100124-01'])->save();
        $dayTwoOlderLoan->forceFill(['start_date' => '2023-01-10', 'payment_day' => 2, 'folio' => 'DAY2-100123-01'])->save();

        $report = $this->service->build(['cutoff_date' => '2026-08-10'], $admin);

        $this->assertSame([$dayOneOldLoan->id, $dayOneNewLoan->id, $dayTwoOlderLoan->id], $report['detail_rows']->pluck('loan_id')->all());
        $this->assertSame(['OLD-100124-01', 'NEW-100126-01', 'DAY2-100123-01'], $report['detail_rows']->pluck('folio')->all());
    }

    public function test_operator_summary_counts_loans_not_visible_installment_rows(): void
    {
        [$admin, $operator] = $this->admin();
        $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-01', 'amount' => '1000.00'],
            ['number' => 2, 'due_date' => '2026-08-05', 'amount' => '1000.00'],
        ], $operator);
        $this->loanWithInstallments([
            ['number' => 1, 'due_date' => '2026-08-10', 'amount' => '1000.00'],
        ], $operator);

        $report = $this->service->build(['cutoff_date' => '2026-08-10'], $admin);
        $operatorRow = $report['operator_rows']->firstWhere('operator_id', $operator->id);

        $this->assertSame(2, $operatorRow['loans_count']);
        $this->assertSame(3, $report['detail_rows']->count());
    }

    /**
     * @return array{0: User, 1: Operator, 2: User}
     */
    private function admin(): array
    {
        $admin = $this->user('Admin', 'admin@orvix.test', 'administrador-general');
        $operatorUser = $this->user('Samuel Operador', 'samuel@orvix.test', 'operador-cartera');
        $operator = $this->operator($operatorUser, 'Samuel');

        return [$admin, $operator, $operatorUser];
    }

    private function loanRow(User $user, Loan $loan, string $cutoffDate): array
    {
        $report = $this->service->build(['cutoff_date' => $cutoffDate], $user);

        return $report['loan_rows']->firstWhere('loan_id', $loan->id) ?? [
            'pending_cents' => 0,
            'overdue_cents' => 0,
            'due_today_cents' => 0,
            'installments' => collect(),
        ];
    }

    /**
     * @param  list<array{number:int,due_date:string,amount:string}>  $installments
     */
    private function loanWithInstallments(array $installments, ?Operator $operator = null): Loan
    {
        if (! $operator) {
            [, $operator] = $this->admin();
        }

        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'operator_id' => $operator->id,
            'first_name' => 'Cliente',
            'last_name' => Str::random(6),
            'phone' => '9991234567',
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'brand' => 'Nissan',
            'model' => 'March',
            'year' => 2020,
            'plates' => strtoupper(Str::random(3)).'-'.random_int(100, 999),
            'status' => 'financed',
        ]);

        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'TEST-'.Str::upper(Str::random(8)),
            'client_id' => $client->id,
            'operator_id' => $operator->id,
            'vehicle_id' => $vehicle->id,
            'capital' => '10000.00',
            'monthly_rate' => '0.020000',
            'term_months' => max(array_column($installments, 'number')),
            'contract_total' => number_format(collect($installments)->sum(fn ($row) => (float) $row['amount']), 2, '.', ''),
            'start_date' => $installments[0]['due_date'],
            'payment_day' => (int) CarbonImmutable::parse($installments[0]['due_date'])->format('d'),
            'status' => 'active',
        ]);

        foreach ($installments as $row) {
            Installment::query()->create([
                'loan_id' => $loan->id,
                'number' => $row['number'],
                'due_date' => $row['due_date'],
                'contract_amount' => $row['amount'],
                'principal_amount' => '0.00',
                'administration_fee_amount' => '0.00',
                'interest_amount' => '0.00',
                'interest_vat_amount' => '0.00',
                'capital_balance' => '0.00',
                'applied_amount' => '0.00',
                'remaining_amount' => $row['amount'],
                'status' => 'upcoming',
            ]);
        }

        return $loan;
    }

    private function applyPayment(Loan $loan, int $installmentNumber, string $date, string $amount): void
    {
        $installment = $loan->installments()->where('number', $installmentNumber)->firstOrFail();
        $movement = CollectionMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'MOV-'.Str::upper(Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'loan_id' => $loan->id,
            'operator_id' => $loan->operator_id,
            'operated_on' => $date,
            'contract_amount' => $amount,
            'operator_surcharge_amount' => '0.00',
            'external_concepts_amount' => '0.00',
            'type' => 'ordinary',
            'confirmation_status' => 'applied',
        ]);

        PaymentAllocation::query()->create([
            'collection_movement_id' => $movement->id,
            'installment_id' => $installment->id,
            'amount' => $amount,
        ]);

        $installment->update([
            'applied_amount' => $amount,
            'remaining_amount' => Money::decimal(max(0, Money::cents($installment->contract_amount) - Money::cents($amount))),
            'status' => (float) $installment->contract_amount === (float) $amount ? 'confirmed' : 'partial',
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => '9990000000',
                'password' => Hash::make('password'),
                'status' => 'active',
            ],
        );

        $user->assignRole($role);

        return $user;
    }

    private function operator(User $user, string $name): Operator
    {
        return Operator::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'public_id' => (string) Str::ulid(),
                'name' => $name,
                'phone' => $user->phone,
                'email' => $user->email,
                'cut_day' => 5,
                'status' => 'active',
            ],
        );
    }
}
