<?php

namespace Tests\Feature;

use App\Domain\Cuts\CutDeliveryHistory;
use App\Domain\Cuts\CutReceivedCash;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\OperatorLedgerEntry;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WeeklyCut;
use App\Models\WeeklyCutItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CutDeliveryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WeeklyCut $cut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('weekly-cuts.confirm', 'web'));
        $operator = Operator::query()->create([
            'public_id' => (string) Str::ulid(), 'name' => 'DARIO',
        ]);
        $this->cut = WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(),
            'operator_id' => $operator->id,
            'period_starts_on' => '2026-10-19',
            'period_ends_on' => '2026-10-19',
            'settlement_on' => '2026-10-19',
            'status' => 'draft',
        ]);
    }

    private function delivery(string $amount, string $date, ?string $token = null): array
    {
        return [
            'delivery_amount' => $amount,
            'received_on' => $date,
            'delivery_token' => $token ?? (string) Str::uuid(),
        ];
    }

    private function reportedPayment(): array
    {
        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(), 'first_name' => 'CLIENTE',
            'last_name' => 'PRUEBA', 'phone' => '9991234567',
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) Str::ulid(), 'folio' => 'CUT-DELIVERY-TEST',
            'client_id' => $client->id, 'operator_id' => $this->cut->operator_id,
            'capital' => '200000.00', 'monthly_rate' => '0.02', 'term_months' => 1,
            'contract_total' => '250000.00', 'start_date' => '2026-09-19',
            'payment_day' => 19, 'status' => 'active',
        ]);
        $installment = Installment::query()->create([
            'loan_id' => $loan->id, 'number' => 1, 'due_date' => '2026-10-19',
            'principal_amount' => '200000.00', 'interest_amount' => '50000.00',
            'contract_amount' => '250000.00', 'remaining_amount' => '250000.00',
        ]);
        $investor = Investor::query()->create([
            'public_id' => (string) Str::ulid(), 'name' => 'INVERSIONISTA PRUEBA',
            'status' => 'active',
        ]);
        Investment::query()->create([
            'public_id' => (string) Str::ulid(), 'loan_id' => $loan->id,
            'investor_id' => $investor->id, 'amount' => '200000.00',
            'investor_share_rate' => 1, 'administrator_share_rate' => 0,
            'starts_on' => '2026-09-19', 'status' => 'active',
        ]);
        $movement = CollectionMovement::query()->create([
            'public_id' => (string) Str::ulid(), 'folio' => 'MOV-DELIVERY-TEST',
            'idempotency_key' => (string) Str::uuid(), 'loan_id' => $loan->id,
            'target_installment_id' => $installment->id, 'operator_id' => $this->cut->operator_id,
            'weekly_cut_id' => $this->cut->id, 'registered_by' => $this->admin->id,
            'operated_on' => '2026-10-19', 'contract_amount' => '250000.00',
            'type' => 'ordinary', 'payment_method' => 'cash',
            'confirmation_status' => 'reported', 'affects_investors' => true,
        ]);
        WeeklyCutItem::query()->create([
            'weekly_cut_id' => $this->cut->id, 'collection_movement_id' => $movement->id,
            'expected_amount' => '250000.00', 'reported_amount' => '250000.00',
        ]);
        $this->cut->update(['reported_total' => '250000.00']);

        return [$movement, $installment, $investor];
    }

    public function test_deliveries_accumulate_with_dates_and_do_not_repeat_allocations_or_returns(): void
    {
        [$movement, $installment, $investor] = $this->reportedPayment();
        $first = $this->delivery('200000.00', '2026-10-20');
        $this->actingAs($this->admin)->post(route('cuts.confirm', $this->cut), $first)
            ->assertSessionHasNoErrors()->assertRedirect(route('cuts.show', $this->cut));

        $this->assertSame(20000000, Money::cents($this->cut->fresh()->received_total));
        $this->assertSame('partially_received', $this->cut->fresh()->status);
        $this->assertSame('applied', $movement->fresh()->confirmation_status);
        $this->assertSame(0, Money::cents($installment->fresh()->remaining_amount));
        $this->assertSame(20000000, Money::cents($investor->fresh()->returned_capital_balance));
        $this->assertSame(5000000, Money::cents($investor->fresh()->generated_interest_balance));
        $ledgerId = OperatorLedgerEntry::query()->where('weekly_cut_id', $this->cut->id)->sole()->id;

        // Network retry / double click must not add another delivery or repeat returns.
        $this->post(route('cuts.confirm', $this->cut), $first)->assertSessionHasNoErrors();
        $this->assertSame(20000000, Money::cents($this->cut->fresh()->received_total));
        $this->assertSame(1, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());

        $this->post(route('cuts.confirm', $this->cut), $this->delivery('50000.00', '2026-10-22'))
            ->assertSessionHasNoErrors();
        $cut = $this->cut->fresh();
        $this->assertSame(25000000, Money::cents($cut->received_total));
        $this->assertSame(0, Money::cents($cut->difference_total));
        $this->assertSame('balanced', $cut->status);
        $this->assertSame('2026-10-22', $cut->confirmed_at->toDateString());
        $this->assertSame(1, PaymentAllocation::query()->where('collection_movement_id', $movement->id)->count());
        $this->assertSame(1, InvestorCapitalMovement::query()->where('type', 'payment_returns_recorded')->count());
        $this->assertSame(20000000, Money::cents($investor->fresh()->returned_capital_balance));
        $this->assertSame(5000000, Money::cents($investor->fresh()->generated_interest_balance));
        $this->assertDatabaseHas('operator_ledger_entries', ['id' => $ledgerId, 'type' => 'historical_shortfall', 'amount' => '50000.00']);
        $this->assertSame(1, OperatorLedgerEntry::query()->where('weekly_cut_id', $cut->id)->where('type', 'confirmed_delivery')->count());
        $this->assertSame(2, OperatorLedgerEntry::query()->where('weekly_cut_id', $cut->id)->count());
        $this->assertSame(['2026-10-20', '2026-10-22'], app(CutDeliveryHistory::class)->history($cut)->pluck('received_on')->all());

        $this->get(route('cuts.show', $cut))->assertOk()
            ->assertSee('Nuevo abono recibido')->assertSee('Historial de entregas')
            ->assertSee('20/10/2026')->assertSee('22/10/2026');
        $this->post(route('cuts.close', $cut))->assertSessionHasNoErrors();
        $this->post(route('cuts.confirm', $cut), $this->delivery('1', '2026-10-23'))->assertStatus(422);
        $this->get(route('cuts.show', $cut))->assertOk()->assertSee('Historial de entregas')
            ->assertDontSee('name="delivery_amount"', false);
        $this->assertSame(2, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());
    }

    public function test_existing_received_balance_is_preserved_without_inventing_individual_deliveries(): void
    {
        $this->cut->update([
            'received_total' => '200000.00', 'confirmed_total' => '200000.00',
            'confirmed_at' => '2026-10-20 23:59:59', 'confirmed_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)->post(route('cuts.confirm', $this->cut), $this->delivery('50000', '2026-10-22'))
            ->assertSessionHasNoErrors();
        $history = app(CutDeliveryHistory::class)->history($this->cut->fresh());
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['opening']);
        $this->assertSame('200000.00', $history[0]['amount']);
        $this->assertSame('2026-10-20', $history[0]['received_on']);
        $this->assertSame('50000.00', $history[1]['amount']);
        $this->assertSame(25000000, Money::cents($this->cut->fresh()->received_total));
    }

    public function test_rejected_requests_preserve_received_money_and_history(): void
    {
        $this->actingAs($this->admin);
        foreach (['0', '-1', '1.001'] as $invalidAmount) {
            $this->post(route('cuts.confirm', $this->cut), $this->delivery($invalidAmount, '2026-10-20'))
                ->assertSessionHasErrors('delivery_amount');
        }
        $this->post(route('cuts.confirm', $this->cut), [
            'received_total' => '200000', 'received_on' => '2026-10-20',
        ])->assertSessionHasErrors(['delivery_amount', 'delivery_token']);
        $this->post(route('cuts.confirm', $this->cut), $this->delivery('100', 'invalid-date'))
            ->assertSessionHasErrors('received_on');
        $this->assertSame(0, Money::cents($this->cut->fresh()->received_total));
        $this->assertSame(0, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());

        $this->actingAs(User::factory()->create())
            ->post(route('cuts.confirm', $this->cut), $this->delivery('100', '2026-10-20'))
            ->assertForbidden();
    }

    public function test_equal_deliveries_with_distinct_tokens_are_both_recorded(): void
    {
        $this->actingAs($this->admin);
        for ($delivery = 0; $delivery < 2; $delivery++) {
            $this->post(route('cuts.confirm', $this->cut), $this->delivery('100.25', '2026-10-20'))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(20050, Money::cents($this->cut->fresh()->received_total));
        $this->assertSame(2, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());
    }

    public function test_total_limit_failure_rolls_back_the_opening_balance_and_delivery(): void
    {
        $this->cut->update(['received_total' => '9999999999999.00']);
        $this->actingAs($this->admin)
            ->post(route('cuts.confirm', $this->cut), $this->delivery('1.00', '2026-10-20'))
            ->assertStatus(422);
        $this->assertSame('9999999999999.00', Money::decimal(Money::cents($this->cut->fresh()->received_total)));
        $this->assertSame(0, AuditEvent::query()->whereIn('action', [
            'weekly_cut.delivery_recorded', 'weekly_cut.delivery_opening_balance',
        ])->count());
    }

    public function test_all_operators_use_individual_deliveries_with_independent_duplicate_protection(): void
    {
        $otherOperator = Operator::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'OTRO OPERADOR']);
        $otherCut = WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $otherOperator->id,
            'period_starts_on' => '2026-10-19', 'period_ends_on' => '2026-10-19', 'status' => 'forming',
        ]);
        $this->actingAs($this->admin);
        $firstToken = (string) Str::uuid();
        foreach ([$this->cut, $otherCut] as $cut) {
            $first = $this->delivery('200000', '2026-10-20', $firstToken);
            $this->post(route('cuts.confirm', $cut), $first)->assertSessionHasNoErrors();
            $this->post(route('cuts.confirm', $cut), $first)->assertSessionHasNoErrors();
            $this->post(route('cuts.confirm', $cut), $this->delivery('50000', '2026-10-22'))->assertSessionHasNoErrors();
            $this->assertSame(25000000, Money::cents($cut->fresh()->received_total));
            $this->assertSame(['2026-10-20', '2026-10-22'], app(CutDeliveryHistory::class)->history($cut->fresh())->pluck('received_on')->all());
            $this->get(route('cuts.show', $cut))->assertOk()
                ->assertSee('Nuevo abono recibido')->assertSee('Historial de entregas')
                ->assertDontSee('name="received_total"', false);
        }
        $this->assertSame(4, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());
        $this->assertCashIndicators('2026-10', '500000');
    }

    public function test_old_closed_cuts_keep_their_balance_and_allow_a_new_delivery_only_after_reopening(): void
    {
        $this->cut->update([
            'status' => 'closed', 'received_total' => '200000.00', 'confirmed_total' => '200000.00',
            'confirmed_at' => '2026-10-20 23:59:59', 'confirmed_by' => $this->admin->id,
        ]);
        $before = $this->cut->fresh()->getAttributes();
        $this->actingAs($this->admin)->get(route('cuts.show', $this->cut))->assertOk()
            ->assertSee('Recibido antes del ajuste (acumulado)')->assertDontSee('name="delivery_amount"', false);
        $this->post(route('cuts.confirm', $this->cut), $this->delivery('50000', '2026-11-01'))->assertStatus(422);
        $this->assertSame($before, $this->cut->fresh()->getAttributes());
        $this->assertSame(0, AuditEvent::query()->where('action', 'weekly_cut.delivery_recorded')->count());

        $this->post(route('cuts.reopen', $this->cut), ['reason' => 'Entrega posterior'])->assertSessionHasNoErrors();
        $this->post(route('cuts.confirm', $this->cut), $this->delivery('50000', '2026-11-01'))->assertSessionHasNoErrors();
        $this->assertSame(25000000, Money::cents($this->cut->fresh()->received_total));
        $this->assertCount(2, app(CutDeliveryHistory::class)->history($this->cut->fresh()));
        $this->assertCashIndicators('2026-10', '200000');
        $this->assertCashIndicators('2026-11', '50000');
    }

    private function assertCashIndicators(string $month, string $expected, ?int $operatorId = null): void
    {
        $filters = array_filter(['operator_id' => $operatorId]);
        $expectedCents = Money::cents($expected);
        $this->get(route('dashboard', [...$filters, 'period' => $month]))->assertOk()
            ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('title', 'Cobrado del periodo')['cents'] === $expectedCents);
        $this->get(route('cuts.index', [...$filters, 'month' => $month]))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['received_cents'] === $expectedCents)
            ->assertViewHas('operatorSummaries', fn ($summaries) => $summaries->sum('received_cents') === $expectedCents);
        $this->get(route('collections.index', [...$filters, 'month' => $month]))->assertOk()
            ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('title', 'Cobrado del mes')['value'] === Money::mxn($expected));
    }

    public function test_cash_indicators_include_open_cut_deliveries_and_keep_each_receipt_in_its_month(): void
    {
        $this->reportedPayment();
        // Neither the cut date nor the final receipt date is in October.
        $this->cut->update(['period_starts_on' => '2026-09-19', 'period_ends_on' => '2026-09-19']);
        $this->actingAs($this->admin);
        $this->post(route('cuts.confirm', $this->cut), $this->delivery('200000', '2026-10-31'))
            ->assertSessionHasNoErrors();
        $this->assertCashIndicators('2026-10', '200000');
        $this->assertCashIndicators('2026-11', '0');

        $this->post(route('cuts.confirm', $this->cut), $this->delivery('50000', '2026-11-01'))
            ->assertSessionHasNoErrors();
        $this->assertCashIndicators('2026-10', '200000');
        $this->assertCashIndicators('2026-11', '50000');
        $this->get(route('cuts.index', ['month' => '2026-10']))->assertViewHas('cuts', fn ($cuts) => $cuts->contains('id', $this->cut->id));
        $this->post(route('cuts.close', $this->cut))->assertSessionHasNoErrors();
        $this->assertCashIndicators('2026-10', '200000');
        $this->assertCashIndicators('2026-11', '50000');
        $this->get(route('dashboard', ['period_type' => 'year', 'period' => '2026']))->assertOk()
            ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('title', 'Cobrado del periodo')['cents'] === 25000000);
        $this->get(route('cuts.index'))->assertViewHas('summary', fn ($summary) => $summary['received_cents'] === 25000000);
        $this->assertSame(1, InvestorCapitalMovement::query()->where('type', 'payment_returns_recorded')->count());
    }

    public function test_cash_indicators_preserve_previous_receipts_as_opening_balance_in_their_recorded_month(): void
    {
        $this->cut->update([
            'received_total' => '70000', 'confirmed_total' => '70000',
            'confirmed_at' => '2026-09-30 23:59:59', 'confirmed_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)
            ->post(route('cuts.confirm', $this->cut), $this->delivery('5000', '2026-10-01'))
            ->assertSessionHasNoErrors();
        $this->assertCashIndicators('2026-09', '70000');
        $this->assertCashIndicators('2026-10', '5000');
    }

    public function test_legacy_confirmed_cash_counts_without_closing_and_reopening_does_not_remove_it(): void
    {
        $this->actingAs($this->admin);
        $this->assertCashIndicators('2026-10', '0');
        foreach (['partially_received', 'balanced', 'closed', 'reopened'] as $status) {
            $this->cut->update([
                'status' => $status, 'received_total' => '200000', 'confirmed_total' => '200000',
                'confirmed_at' => '2026-10-21 23:59:59',
            ]);
            $this->assertCashIndicators('2026-10', '200000');
        }
        $this->assertCashIndicators('2026-09', '0');
    }

    public function test_cash_indicators_respect_operator_filters_and_operator_access_scope(): void
    {
        $this->cut->update(['received_total' => '200000', 'confirmed_at' => '2026-10-21 23:59:59']);
        $otherOperator = Operator::query()->create(['public_id' => (string) Str::ulid(), 'name' => 'OTRO OPERADOR']);
        WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $otherOperator->id,
            'period_starts_on' => '2026-10-19', 'period_ends_on' => '2026-10-19',
            'received_total' => '5000', 'confirmed_at' => '2026-10-21 23:59:59',
        ]);
        $this->actingAs($this->admin);
        $this->assertCashIndicators('2026-10', '205000');
        $this->assertCashIndicators('2026-10', '200000', $this->cut->operator_id);
        $this->assertCashIndicators('2026-10', '5000', $otherOperator->id);

        $operatorUser = User::factory()->create();
        $operatorUser->assignRole(Role::findOrCreate('operador-cartera', 'web'));
        $this->cut->operator->update(['user_id' => $operatorUser->id]);
        $this->actingAs($operatorUser);
        $this->assertCashIndicators('2026-10', '200000', $otherOperator->id);
    }

    public function test_cash_calculation_is_read_only_and_does_not_add_cumulative_total_to_deliveries(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cuts.confirm', $this->cut), $this->delivery('200000', '2026-10-21'))
            ->assertSessionHasNoErrors();
        $before = $this->cut->fresh()->getAttributes();
        $auditCount = AuditEvent::query()->count();
        $cash = app(CutReceivedCash::class);
        for ($read = 0; $read < 2; $read++) {
            $this->assertSame(20000000, $cash->total(WeeklyCut::query(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
            $this->assertCashIndicators('2026-10', '200000');
        }
        $this->assertSame($before, $this->cut->fresh()->getAttributes());
        $this->assertSame($auditCount, AuditEvent::query()->count());
    }
}
