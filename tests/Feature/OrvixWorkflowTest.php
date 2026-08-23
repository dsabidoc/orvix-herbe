<?php

namespace Tests\Feature;

use App\Domain\Loans\LoanSettlementService;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Document;
use App\Models\FundDisbursement;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\Operator;
use App\Models\OperatorLedgerEntry;
use App\Models\Simulation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WeeklyCut;
use App\Models\WeeklyCutItem;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrvixWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_only_open_assigned_loans(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $samuelLoan = Loan::query()->where('operator_id', $samuel->operatorProfile->id)->firstOrFail();
        $darioLoan = Loan::query()
            ->where('operator_id', '!=', $samuel->operatorProfile->id)
            ->whereHas('operator', fn ($query) => $query->where('name', 'Dario'))
            ->firstOrFail();

        $this->actingAs($samuel)->get(route('loans.show', $samuelLoan))->assertOk();
        $this->actingAs($samuel)->get(route('loans.show', $darioLoan))->assertForbidden();
    }

    public function test_creating_operator_user_creates_operator_profile_for_loan_forms(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('settings.users.store'), [
                'name' => 'Nuevo Operador',
                'email' => 'nuevo.operador@orvix.test',
                'password' => 'orvix-demo',
                'role' => 'operador-cartera',
            ])
            ->assertSessionHas('status');

        $operatorUser = User::query()->where('email', 'nuevo.operador@orvix.test')->firstOrFail();

        $this->assertDatabaseHas('operators', [
            'user_id' => $operatorUser->id,
            'name' => 'NUEVO OPERADOR',
            'email' => 'nuevo.operador@orvix.test',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('loans.create'))
            ->assertOk()
            ->assertSee('NUEVO OPERADOR');
    }

    public function test_loan_quote_validates_optional_phone_fields_have_ten_digits(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('loans.create'))
            ->post(route('loans.quote-rounded'), [
                'operator_id' => $operator->id,
                'first_name' => 'Cliente',
                'last_name' => 'Con Telefono Mal',
                'phone' => '999123456',
                'email' => '',
                'capital' => '100000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'administration_fee' => '0',
                'term_months' => '12',
                'start_date' => '2026-08-14',
                'first_payment_date' => '2026-09-14',
                'payment_day' => '14',
                'calculation_method' => 'rounded',
                'vat_enabled' => '0',
                'interest_calculation_method' => 'fixed_principal',
                'guarantor_name' => 'Aval Existente',
                'guarantor_phone' => '99912345678',
            ])
            ->assertRedirect(route('loans.create'))
            ->assertSessionHasErrors(['phone', 'guarantor_phone']);
    }

    public function test_admin_can_merge_duplicate_clients_without_deleting_them(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $primary = Client::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'operator_id' => $operator->id,
            'first_name' => 'Darwin Ramon',
            'last_name' => 'Cervera Duran',
            'phone' => '9997069555',
            'email' => null,
            'status' => 'active',
        ]);
        $duplicate = Client::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'operator_id' => $operator->id,
            'first_name' => 'Darwin Ramon',
            'last_name' => 'Cervera Duran',
            'phone' => '9997069556',
            'email' => 'darwin.duplicado@orvix.test',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'client_id' => $duplicate->id,
            'brand' => 'NISSAN',
            'model' => 'MARCH',
            'status' => 'financed',
        ]);
        $loan = Loan::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'folio' => 'DAR-140826-98',
            'client_id' => $duplicate->id,
            'operator_id' => $operator->id,
            'vehicle_id' => $vehicle->id,
            'capital' => '100000.00',
            'monthly_rate' => '0.020000',
            'term_months' => 12,
            'contract_total' => '124000.00',
            'start_date' => '2026-08-14',
            'payment_day' => 14,
            'status' => 'active',
        ]);
        $application = LoanApplication::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'folio' => 'SOL-TEST',
            'client_id' => $duplicate->id,
            'operator_id' => $operator->id,
            'requested_capital' => '100000.00',
            'monthly_rate' => '0.020000',
            'term_months' => 12,
            'payment_day' => 14,
            'status' => 'submitted',
        ]);
        $document = Document::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'loan_id' => $loan->id,
            'client_id' => $duplicate->id,
            'original_name' => 'factura.pdf',
            'disk' => 'private',
            'path' => 'documents/factura.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1200,
            'status' => 'delivered',
        ]);

        $this->actingAs($admin)
            ->post(route('settings.client-merge.store'), [
                'primary_client_id' => $primary->id,
                'duplicate_client_ids' => [$duplicate->id],
            ])
            ->assertRedirect(route('settings.client-merge'));

        $this->assertDatabaseHas('clients', [
            'id' => $duplicate->id,
            'status' => 'merged',
        ]);
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'client_id' => $primary->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'client_id' => $primary->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'client_id' => $primary->id]);
        $this->assertDatabaseHas('loan_applications', ['id' => $application->id, 'client_id' => $primary->id]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clients.merged',
            'auditable_id' => $primary->id,
        ]);
    }

    public function test_existing_active_user_can_be_linked_when_creating_investor_profile(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $investorUser = User::query()->create([
            'name' => 'Usuario Operador Inversionista',
            'email' => 'usuario.operador.inversionista@orvix.test',
            'phone' => '9991234567',
            'password' => 'orvix-demo',
            'status' => 'active',
        ]);
        $investorUser->assignRole('operador-cartera');

        $this->actingAs($admin)
            ->get(route('investors.index'))
            ->assertOk()
            ->assertSee('Usuario Operador Inversionista')
            ->assertSee('usuario.operador.inversionista@orvix.test');

        $this->actingAs($admin)
            ->post(route('investors.store'), [
                'user_id' => $investorUser->id,
                'first_name' => 'Usuario',
                'last_name' => 'Operador Inversionista',
                'email' => 'usuario.operador.inversionista@orvix.test',
                'phone' => '9991234567',
                'initial_capital' => '250000',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('investors', [
            'user_id' => $investorUser->id,
            'name' => 'USUARIO OPERADOR INVERSIONISTA',
            'email' => 'usuario.operador.inversionista@orvix.test',
            'initial_capital' => '250000.00',
            'available_capital' => '250000.00',
        ]);
    }

    public function test_admin_can_delete_investor_without_live_active_loans(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $investor = Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'first_name' => 'Sin',
            'last_name' => 'Prestamos',
            'name' => 'Sin Prestamos',
            'email' => 'sin.prestamos@orvix.test',
            'phone' => '9990000000',
            'initial_capital' => '0.00',
            'available_capital' => '0.00',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('investors.destroy', $investor))
            ->assertRedirect(route('investors.index'))
            ->assertSessionHas('status', 'Inversionista eliminado.');

        $this->assertDatabaseHas('investors', [
            'id' => $investor->id,
            'status' => 'deleted',
        ]);

        $this->actingAs($admin)
            ->get(route('investors.index'))
            ->assertOk()
            ->assertDontSee('Sin Prestamos');
    }

    public function test_deleted_investor_user_can_be_reactivated_from_create_modal(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $user = User::query()->create([
            'name' => 'Maria Gabriela Ramirez Redondo',
            'email' => 'maria.gabriela.ramirez@orvix.test',
            'phone' => '9997776666',
            'password' => 'orvix-demo',
            'status' => 'active',
        ]);
        $investor = Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $user->id,
            'first_name' => 'Maria Gabriela',
            'last_name' => 'Ramirez Redondo',
            'name' => 'Maria Gabriela Ramirez Redondo',
            'email' => $user->email,
            'phone' => $user->phone,
            'initial_capital' => '0.00',
            'available_capital' => '0.00',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('investors.destroy', $investor))
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->get(route('investors.index'))
            ->assertOk()
            ->assertSee($user->email);

        $this->actingAs($admin)
            ->post(route('investors.store'), [
                'user_id' => $user->id,
                'first_name' => 'Maria Gabriela',
                'last_name' => 'Ramirez Redondo',
                'email' => $user->email,
                'phone' => $user->phone,
                'initial_capital' => '123000',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('investors', [
            'id' => $investor->id,
            'user_id' => $user->id,
            'name' => 'MARIA GABRIELA RAMIREZ REDONDO',
            'status' => 'active',
            'initial_capital' => '123000.00',
            'available_capital' => '123000.00',
        ]);
    }

    public function test_admin_can_edit_investor_initial_capital_and_available_delta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $investor = Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'first_name' => 'Capital',
            'last_name' => 'Editable',
            'name' => 'Capital Editable',
            'email' => 'capital.editable@orvix.test',
            'phone' => '9998887777',
            'initial_capital' => '100000.00',
            'available_capital' => '100000.00',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->put(route('investors.update', $investor), [
                'initial_capital' => '125000',
                'available_capital' => '125000',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Capital del inversionista actualizado.');

        $investor->refresh();

        $this->assertSame(12500000, Money::cents($investor->initial_capital));
        $this->assertSame(12500000, Money::cents($investor->available_capital));
        $this->assertDatabaseHas('investor_capital_movements', [
            'investor_id' => $investor->id,
            'type' => 'available_capital_adjusted',
            'amount' => '25000',
        ]);
    }

    public function test_admin_cannot_delete_investor_with_live_active_loan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $investor = Investor::query()
            ->whereHas('investments', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('loan', fn ($loanQuery) => $loanQuery->where('status', 'active')))
            ->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('investors.destroy', $investor))
            ->assertRedirect()
            ->assertSessionHasErrors('investor');

        $this->assertDatabaseHas('investors', [
            'id' => $investor->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_confirmation_applies_reported_payment_to_installments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $movement = CollectionMovement::query()
            ->where('confirmation_status', 'reported')
            ->where('type', 'ordinary')
            ->firstOrFail();

        $before = $movement->loan->installments()->sum('remaining_amount');
        $investment = $movement->loan->investments()->with('investor')->firstOrFail();
        $returnedBefore = Money::cents($investment->investor->returned_capital_balance);
        $interestBefore = Money::cents($investment->investor->generated_interest_balance);

        $this->actingAs($admin)
            ->post(route('payments.confirm', $movement))
            ->assertSessionHas('status');

        $movement->refresh();
        $after = $movement->loan->installments()->sum('remaining_amount');

        $this->assertSame('applied', $movement->confirmation_status);
        $this->assertGreaterThan($after, $before);
        $this->assertDatabaseHas('payment_allocations', [
            'collection_movement_id' => $movement->id,
        ]);
        $this->assertGreaterThan($returnedBefore, Money::cents($investment->investor->fresh()->returned_capital_balance));
        $this->assertGreaterThanOrEqual($interestBefore, Money::cents($investment->investor->fresh()->generated_interest_balance));
    }

    public function test_admin_can_return_paid_installment_to_pending(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $movement = CollectionMovement::query()
            ->where('confirmation_status', 'reported')
            ->where('type', 'ordinary')
            ->whereNotNull('target_installment_id')
            ->firstOrFail();
        $installment = $movement->targetInstallment()->firstOrFail();
        $originalRemaining = $installment->remaining_amount;

        $this->actingAs($admin)
            ->post(route('payments.confirm', $movement))
            ->assertSessionHas('status');

        $this->assertSame('applied', $movement->fresh()->confirmation_status);
        $this->assertDatabaseHas('payment_allocations', [
            'collection_movement_id' => $movement->id,
        ]);

        $this->actingAs($admin)
            ->post(route('payments.reverse', $movement))
            ->assertSessionHas('status');

        $installment->refresh();
        $movement->refresh();

        $this->assertSame('reversed', $movement->confirmation_status);
        $this->assertSame($originalRemaining, $installment->remaining_amount);
        $this->assertSame(0, Money::cents($installment->applied_amount));
        $this->assertSame('upcoming', $installment->status);
        $this->assertDatabaseMissing('payment_allocations', [
            'collection_movement_id' => $movement->id,
        ]);
        $this->assertNull($installment->reportedMovement()->first());
    }

    public function test_installment_operational_balance_excludes_vat_and_administration_fee(): void
    {
        $this->seed(DatabaseSeeder::class);

        $installment = Installment::query()
            ->where(function ($query) {
                $query->where('administration_fee_amount', '>', 0)
                    ->orWhere('interest_vat_amount', '>', 0);
            })
            ->where('remaining_amount', '>', 0)
            ->firstOrFail();

        $operationalCents = Money::cents($installment->principal_amount) + Money::cents($installment->interest_amount);

        $this->assertSame($operationalCents, Money::cents($installment->remaining_amount));
        $this->assertGreaterThan($operationalCents, Money::cents($installment->contract_amount));
    }

    public function test_paid_without_investor_effects_applies_installment_without_recording_returns(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()
            ->whereHas('investments')
            ->whereHas('installments', fn ($query) => $query->where('remaining_amount', '>', 0)->whereDoesntHave('reportedMovement'))
            ->with(['installments' => fn ($query) => $query->where('remaining_amount', '>', 0)->whereDoesntHave('reportedMovement')->orderBy('number')])
            ->firstOrFail();
        $installment = $loan->installments->first();
        $returnsBefore = InvestorCapitalMovement::query()->where('type', 'payment_returns_recorded')->count();

        $this->actingAs($admin)
            ->post(route('collections.mark-paid', $installment), [
                'operated_on' => '2026-08-11',
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
                'affects_investors' => 0,
                'return_to' => 'loan',
            ])
            ->assertSessionHas('status');

        $movement = CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail();

        $this->assertFalse($movement->affects_investors);
        $this->assertSame('applied', $movement->confirmation_status);

        $this->assertSame(0, Money::cents($installment->fresh()->remaining_amount));
        $this->assertSame($returnsBefore, InvestorCapitalMovement::query()->where('type', 'payment_returns_recorded')->count());
    }

    public function test_advance_payment_must_cover_full_trailing_installments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()
            ->where('status', 'active')
            ->whereHas('installments', fn ($query) => $query->where('remaining_amount', '>', 0))
            ->with(['installments' => fn ($query) => $query->where('remaining_amount', '>', 0)->orderByDesc('number')])
            ->firstOrFail();
        $lastInstallment = $loan->installments->first();
        $remainingBefore = $lastInstallment->remaining_amount;
        $lastPrincipalCents = Money::cents($lastInstallment->principal_amount);
        $invalidAmount = Money::decimal($lastPrincipalCents + 100);

        $movement = CollectionMovement::query()->create([
            'public_id' => (string) str()->ulid(),
            'folio' => 'MOV-ADV-TEST',
            'idempotency_key' => (string) str()->uuid(),
            'loan_id' => $loan->id,
            'operator_id' => $loan->operator_id,
            'registered_by' => $admin->id,
            'operated_on' => '2026-08-10',
            'contract_amount' => $invalidAmount,
            'operator_surcharge_amount' => '0.00',
            'external_concepts_amount' => '0.00',
            'type' => 'advance',
            'payment_method' => 'cash',
            'confirmation_status' => 'reported',
        ]);

        $this->actingAs($admin)
            ->post(route('payments.confirm', $movement))
            ->assertSessionHas('warning');

        $this->assertSame($remainingBefore, $lastInstallment->fresh()->remaining_amount);
    }

    public function test_advance_payment_covers_last_installment_with_principal_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()
            ->where('status', 'active')
            ->whereHas('installments', fn ($query) => $query->where('remaining_amount', '>', 0)->where('principal_amount', '>', 0))
            ->with(['installments' => fn ($query) => $query->where('remaining_amount', '>', 0)->orderByDesc('number')])
            ->firstOrFail();
        $lastInstallment = $loan->installments->first();
        $principalOnly = Money::decimal(Money::cents($lastInstallment->principal_amount));

        $movement = CollectionMovement::query()->create([
            'public_id' => (string) str()->ulid(),
            'folio' => 'MOV-ADV-PRINCIPAL-TEST',
            'idempotency_key' => (string) str()->uuid(),
            'loan_id' => $loan->id,
            'operator_id' => $loan->operator_id,
            'registered_by' => $admin->id,
            'operated_on' => '2026-08-10',
            'contract_amount' => $principalOnly,
            'operator_surcharge_amount' => '0.00',
            'external_concepts_amount' => '0.00',
            'type' => 'advance',
            'payment_method' => 'cash',
            'confirmation_status' => 'reported',
        ]);

        $this->actingAs($admin)
            ->post(route('payments.confirm', $movement))
            ->assertSessionHas('status');

        $lastInstallment->refresh();

        $this->assertSame('advanced', $lastInstallment->status);
        $this->assertSame(0, Money::cents($lastInstallment->remaining_amount));
        $this->assertSame(Money::cents($principalOnly), Money::cents($lastInstallment->applied_amount));
    }

    public function test_settlement_charges_future_principal_without_future_interest(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $settledOn = CarbonImmutable::parse('2026-08-10', 'America/Merida');
        $loan = Loan::query()
            ->where('status', 'active')
            ->whereHas('installments', fn ($query) => $query
                ->whereDate('due_date', '>', $settledOn->endOfMonth()->toDateString())
                ->where('remaining_amount', '>', 0)
                ->where('interest_amount', '>', 0))
            ->with('installments')
            ->firstOrFail();
        $quote = app(LoanSettlementService::class)->quote($loan, $settledOn);
        $remainingBeforeCents = $loan->installments->sum(fn (Installment $installment) => Money::cents($installment->remaining_amount));

        $this->assertGreaterThan($quote['total_cents'], $remainingBeforeCents);

        $this->actingAs($admin)
            ->post(route('loans.settle', $loan), [
                'settlement_reason' => 'pronto_pago_cliente',
                'settled_on' => $settledOn->toDateString(),
            ])
            ->assertRedirect(route('loans.show', $loan));

        $movement = CollectionMovement::query()->where('loan_id', $loan->id)->where('type', 'settlement')->firstOrFail();

        $this->assertSame($quote['total_cents'], Money::cents($movement->contract_amount));
        $this->assertSame('settled', $loan->fresh()->status);
        $this->assertSame(0, $loan->installments()->sum('remaining_amount') * 100);
    }

    public function test_operator_collection_screen_only_lists_own_installments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();

        $response = $this->actingAs($samuel)->get(route('collections.index', ['month' => '2026-07']));

        $response->assertOk();
        $response->assertSee('Samuel');
        $response->assertDontSee('Dario');
        $response->assertDontSee('Santiago');
    }

    public function test_marking_installment_paid_from_collection_creates_weekly_cut_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)
            ->post(route('collections.mark-paid', $installment), [
                'operated_on' => now('America/Merida')->toDateString(),
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
            ])
            ->assertSessionHas('status');

        $movement = CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail();

        $this->actingAs($samuel)
            ->post(route('cuts.store'))
            ->assertRedirect();

        $this->assertDatabaseHas('weekly_cut_items', [
            'collection_movement_id' => $movement->id,
            'status' => 'included',
        ]);
    }

    public function test_operator_can_mark_payment_from_dashboard_quick_modal(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Registrar Cobro')
            ->assertSee($installment->loan->client->first_name);

        $this->actingAs($samuel)
            ->post(route('collections.mark-paid', $installment), [
                'return_to' => 'dashboard',
                'operated_on' => now('America/Merida')->toDateString(),
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('collection_movements', [
            'target_installment_id' => $installment->id,
            'confirmation_status' => 'reported',
        ]);
    }

    public function test_dashboard_action_buttons_match_user_profile(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $investorUser = Investor::query()->whereNotNull('user_id')->with('user')->firstOrFail()->user;

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Crear Prestamo')
            ->assertSee('Registrar Cobro')
            ->assertDontSee('Solicitar Prestamo');

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Solicitar Prestamo')
            ->assertSee('Registrar Cobro')
            ->assertDontSee('Crear Prestamo');

        $this->actingAs($investorUser)
            ->get(route('dashboard'))
            ->assertRedirect(route('investors.index'));
    }

    public function test_unmarked_installment_rolls_into_next_week_cut_as_overdue(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-04 10:00:00');
        CarbonImmutable::setTestNow('2026-08-04 10:00:00');

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $overdue = Installment::query()
            ->with('loan.client')
            ->whereDate('due_date', '<', '2026-08-04')
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->firstOrFail();

        $response = $this->actingAs($samuel)->post(route('cuts.store'));

        $response->assertRedirect();
        $cutUrl = $response->headers->get('Location');

        $this->actingAs($samuel)
            ->get($cutUrl)
            ->assertOk()
            ->assertSee('Atrasados sin marcar')
            ->assertSee($overdue->loan->client->first_name);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_loan_detail_labels_overdue_installments_consistently(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-04 21:10:00');
        CarbonImmutable::setTestNow('2026-08-04 21:10:00');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()
            ->with('installments')
            ->where('status', 'active')
            ->whereHas('installments', fn ($query) => $query
                ->whereDate('due_date', '<', '2026-08-04')
                ->where('remaining_amount', '>', 0))
            ->firstOrFail();
        $overdueCount = $loan->installments
            ->filter(fn (Installment $installment) => $installment->due_date->toDateString() < '2026-08-04' && Money::cents($installment->remaining_amount) > 0)
            ->count();

        $this->actingAs($admin)
            ->get(route('loans.index', ['bucket' => 'overdue']))
            ->assertOk()
            ->assertSee($loan->client->first_name)
            ->assertSee($overdueCount.' vencida(s)');

        $this->actingAs($admin)
            ->get(route('loans.show', $loan))
            ->assertOk()
            ->assertSee($overdueCount.' letra(s) vencida(s)')
            ->assertSee('Vencida');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_weekly_cut_shortfall_is_carried_to_next_week(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $cut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->where('status', 'submitted')
            ->firstOrFail();

        $received = max(0, (int) floor((float) $cut->reported_total) - 1000);

        $this->actingAs($admin)
            ->post(route('cuts.confirm', $cut), [
                'received_total' => $received,
                'received_on' => '2026-07-31',
                'reason' => 'Faltante demo',
            ])
            ->assertSessionHas('status');

        Carbon::setTestNow('2026-08-04 10:00:00');
        CarbonImmutable::setTestNow('2026-08-04 10:00:00');

        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)->post(route('collections.mark-paid', $installment), [
            'operated_on' => now('America/Merida')->toDateString(),
            'contract_amount' => $installment->remaining_amount,
            'operator_surcharge_amount' => 0,
            'external_concepts_amount' => 0,
        ]);

        $this->actingAs($samuel)->post(route('cuts.store'))->assertRedirect();

        $nextCut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(-100000, Money::cents($nextCut->previous_balance));
        $contractualExpectedCents = (int) round(Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id)->where('status', 'active'))
            ->whereBetween('due_date', [$nextCut->period_starts_on->toDateString(), $nextCut->period_ends_on->toDateString()])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100);
        $this->assertSame($contractualExpectedCents, Money::cents($nextCut->expected_total));

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_settling_weekly_cut_shortfall_clears_next_week_carry(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $cut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->where('status', 'submitted')
            ->firstOrFail();

        $received = max(0, (int) floor((float) $cut->reported_total) - 1000);

        $this->actingAs($admin)->post(route('cuts.confirm', $cut), [
            'received_total' => $received,
            'received_on' => '2026-07-31',
            'reason' => 'Faltante demo',
        ]);

        $cut->refresh();

        $this->actingAs($admin)
            ->post(route('cuts.settle-balance', $cut), [
                'amount' => '1000.00',
                'settled_on' => '2026-07-31',
                'reason' => 'Regulariza faltante',
            ])
            ->assertSessionHas('status');

        Carbon::setTestNow('2026-08-04 10:00:00');
        CarbonImmutable::setTestNow('2026-08-04 10:00:00');

        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)->post(route('collections.mark-paid', $installment), [
            'operated_on' => now('America/Merida')->toDateString(),
            'contract_amount' => $installment->remaining_amount,
            'operator_surcharge_amount' => 0,
            'external_concepts_amount' => 0,
        ]);

        $this->actingAs($samuel)->post(route('cuts.store'))->assertRedirect();

        $nextCut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(0, Money::cents($nextCut->previous_balance));
        $contractualExpectedCents = (int) round(Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id)->where('status', 'active'))
            ->whereBetween('due_date', [$nextCut->period_starts_on->toDateString(), $nextCut->period_ends_on->toDateString()])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100);
        $this->assertSame($contractualExpectedCents, Money::cents($nextCut->expected_total));

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_admin_can_open_new_operational_modules(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)->get(route('clients.index'))->assertOk();
        $this->actingAs($admin)->get(route('clients.create'))->assertOk();
        $this->actingAs($admin)->get(route('loans.create'))->assertOk();
        $this->actingAs($admin)->get(route('simulator.index'))->assertOk();
        $this->actingAs($admin)->get(route('applications.index'))->assertOk();
        $this->actingAs($admin)->get(route('applications.create'))->assertOk();
        $this->actingAs($admin)->get(route('documents.index'))->assertOk();
        $this->actingAs($admin)->get(route('settings.index'))->assertRedirect(route('settings.users'));
        $this->actingAs($admin)->get(route('settings.users'))->assertOk();
        $this->actingAs($admin)->get(route('settings.roles'))->assertOk();
        $this->actingAs($admin)->get(route('settings.permissions'))->assertOk();
    }

    public function test_loan_creation_preserves_input_when_required_conditions_are_missing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('loans.create'))
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Minimo',
                'operator_id' => $operator->id,
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
            ])
            ->assertRedirect(route('loans.create'))
            ->assertSessionHasErrors('capital')
            ->assertSessionHasInput('first_name', 'CLIENTE MINIMO');
    }

    public function test_admin_can_create_loan_with_minimum_client_and_conditions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $investor = Investor::query()->where('available_capital', '>=', 100000)->firstOrFail();
        $availableBeforeCents = Money::cents($investor->available_capital);

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Minimo',
                'operator_id' => $operator->id,
                'capital' => '100000',
                'rate_type' => 'annual',
                'rate_value' => '24',
                'interest_calculation_method' => 'outstanding_balance',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
                'investors' => [
                    ['investor_id' => $investor->id, 'capital_amount' => '100000', 'interest_share_percent' => '100'],
                ],
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE MINIMO'))
            ->firstOrFail();

        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{6}-\d{2}$/', $loan->folio);
        $this->assertSame('outstanding_balance', $loan->interest_calculation_method);
        $this->assertSame(0.02, (float) $loan->monthly_rate);
        $this->assertSame('Sin marca', $loan->vehicle->brand);
        $this->assertSame('Vehiculo', $loan->vehicle->model);
        $this->assertDatabaseHas('investments', [
            'loan_id' => $loan->id,
            'investor_id' => $investor->id,
            'amount' => '100000',
        ]);
        $this->assertSame($availableBeforeCents - 10000000, Money::cents($investor->fresh()->available_capital));
    }

    public function test_admin_can_create_loan_without_initial_investors(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Sin Inversionista',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE SIN INVERSIONISTA'))
            ->firstOrFail();

        $this->assertSame(0, $loan->investments()->count());
        $this->assertSame(80000.0, (float) $loan->capital);
    }

    public function test_loan_creation_rejects_invalid_vin_length(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('loans.create'))
            ->post(route('loans.store'), [
                'first_name' => 'Cliente VIN',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
                'vin' => '123',
            ])
            ->assertRedirect(route('loans.create'))
            ->assertSessionHasErrors('vin');
    }

    public function test_quote_preview_can_restore_create_form_input(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('loans.create.restore'), [
                'first_name' => 'Cliente Regreso',
                'operator_id' => $operator->id,
                'capital' => '123000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 15,
                'start_date' => '2026-08-15',
                'first_payment_date' => '2026-09-15',
                'calculation_method' => 'rounded',
            ])
            ->assertRedirect(route('loans.create'))
            ->assertSessionHasInput('first_name', 'CLIENTE REGRESO')
            ->assertSessionHasInput('capital', '123000');
    }

    public function test_admin_bulk_paid_applies_selected_installments_immediately(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Pago Masivo',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE PAGO MASIVO'))
            ->with(['installments' => fn ($query) => $query->orderBy('number')])
            ->firstOrFail();

        $selected = $loan->installments->take(2);

        $this->actingAs($admin)
            ->post(route('collections.mark-paid.bulk'), [
                'loan_id' => $loan->id,
                'installment_ids' => $selected->pluck('id')->all(),
                'operated_on' => '2026-08-12',
                'affects_investors' => 0,
                'return_to' => 'loan',
            ])
            ->assertRedirect(route('loans.show', $loan));

        foreach ($selected as $installment) {
            $installment->refresh();
            $movement = CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail();

            $this->assertSame('applied', $movement->confirmation_status);
            $this->assertSame(0, Money::cents($installment->remaining_amount));
            $this->assertFalse($movement->affects_investors);
        }
    }

    public function test_can_assign_investor_without_available_capital_during_initial_load(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $investor = Investor::query()->create([
            'public_id' => (string) str()->ulid(),
            'first_name' => 'Sin',
            'last_name' => 'Capital Disponible',
            'name' => 'Sin Capital Disponible',
            'email' => 'sin.capital@orvix.test',
            'initial_capital' => '0.00',
            'available_capital' => '0.00',
            'returned_capital_balance' => '0.00',
            'generated_interest_balance' => '0.00',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Capital Negativo',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
                'investors' => [
                    ['investor_id' => $investor->id, 'capital_amount' => '80000', 'interest_share_percent' => '100'],
                ],
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE CAPITAL NEGATIVO'))
            ->firstOrFail();

        $this->assertDatabaseHas('investments', [
            'loan_id' => $loan->id,
            'investor_id' => $investor->id,
            'amount' => '80000',
        ]);
        $this->assertSame(-8000000, Money::cents($investor->fresh()->available_capital));
    }

    public function test_can_unassign_all_investors_from_loan_during_initial_load(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $investor = Investor::query()->where('available_capital', '>=', 80000)->firstOrFail();
        $availableBeforeCents = Money::cents($investor->available_capital);

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Desasignar Inversionista',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
                'investors' => [
                    ['investor_id' => $investor->id, 'capital_amount' => '80000', 'interest_share_percent' => '100'],
                ],
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE DESASIGNAR INVERSIONISTA'))
            ->firstOrFail();

        $this->assertSame($availableBeforeCents - 8000000, Money::cents($investor->fresh()->available_capital));

        $this->actingAs($admin)
            ->post(route('loans.investments.store', $loan), [
                'investors' => [
                    ['investor_id' => null, 'capital_amount' => '0', 'interest_share_percent' => '0'],
                ],
            ])
            ->assertRedirect(route('loans.show', $loan));

        $this->assertSame(0, $loan->investments()->count());
        $this->assertSame($availableBeforeCents, Money::cents($investor->fresh()->available_capital));
    }

    public function test_assigning_investors_after_confirmed_payment_replays_returns(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $investor = Investor::query()->where('available_capital', '>=', 80000)->firstOrFail();
        $availableBeforeCents = Money::cents($investor->available_capital);
        $returnedBeforeCents = Money::cents($investor->returned_capital_balance);
        $interestBeforeCents = Money::cents($investor->generated_interest_balance);

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Retorno Tardio',
                'operator_id' => $operator->id,
                'capital' => '80000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE RETORNO TARDIO'))
            ->with(['installments' => fn ($query) => $query->orderBy('number')])
            ->firstOrFail();
        $installment = $loan->installments->first();

        $this->actingAs($admin)
            ->post(route('collections.mark-paid', $installment), [
                'operated_on' => '2026-08-10',
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
                'return_to' => 'loan',
            ])
            ->assertSessionHas('status');

        $movement = CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail();

        $this->assertSame('applied', $movement->fresh()->confirmation_status);
        $this->assertSame(0, InvestorCapitalMovement::query()->where('loan_id', $loan->id)->where('type', 'payment_returns_recorded')->count());

        $this->actingAs($admin)
            ->post(route('loans.investments.store', $loan), [
                'investors' => [
                    ['investor_id' => $investor->id, 'capital_amount' => '80000', 'interest_share_percent' => '100'],
                ],
            ])
            ->assertRedirect(route('loans.show', $loan));

        $investor->refresh();

        $this->assertSame($availableBeforeCents - 8000000, Money::cents($investor->available_capital));
        $this->assertSame($returnedBeforeCents + Money::cents($installment->principal_amount), Money::cents($investor->returned_capital_balance));
        $this->assertSame($interestBeforeCents + Money::cents($installment->interest_amount), Money::cents($investor->generated_interest_balance));
        $this->assertDatabaseHas('investor_capital_movements', [
            'loan_id' => $loan->id,
            'investor_id' => $investor->id,
            'type' => 'payment_returns_recorded',
        ]);
    }

    public function test_investor_can_share_interest_without_contributing_capital(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $capitalInvestor = Investor::query()->where('available_capital', '>=', 75000)->firstOrFail();
        $interestOnlyInvestor = Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'first_name' => 'Interes',
            'last_name' => 'Sin Capital',
            'name' => 'Interes Sin Capital',
            'email' => 'interes.sin.capital@orvix.test',
            'phone' => '9995555555',
            'initial_capital' => '0.00',
            'available_capital' => '0.00',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Interes Compartido',
                'operator_id' => $operator->id,
                'capital' => '75000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'payment_day' => 10,
                'start_date' => '2026-08-05',
                'first_payment_date' => '2026-08-10',
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE INTERES COMPARTIDO'))
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('loans.investments.store', $loan), [
                'investors' => [
                    ['investor_id' => $capitalInvestor->id, 'capital_amount' => '75000', 'interest_share_percent' => '50'],
                    ['investor_id' => $interestOnlyInvestor->id, 'capital_amount' => '0', 'interest_share_percent' => '50'],
                ],
            ])
            ->assertRedirect(route('loans.show', $loan));

        $this->assertDatabaseHas('investments', [
            'loan_id' => $loan->id,
            'investor_id' => $interestOnlyInvestor->id,
            'amount' => '0',
            'investor_share_rate' => '0.5',
        ]);
    }

    public function test_create_loan_form_uses_orvix_default_conditions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('loans.create'))
            ->assertOk()
            ->assertSee('value="rounded" selected', false)
            ->assertSee('name="rate_value"', false)
            ->assertSee('value="2"', false)
            ->assertSee('name="delinquency_rate"', false)
            ->assertSee('value="0"', false)
            ->assertSee('value="0" selected', false);
    }

    public function test_rounded_quote_lists_available_investors_for_funding(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $availableInvestor = Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'first_name' => 'Disponible',
            'last_name' => 'Preview',
            'name' => 'Disponible Preview',
            'email' => 'disponible.preview@orvix.test',
            'phone' => '9991112222',
            'initial_capital' => '75000.00',
            'available_capital' => '75000.00',
            'status' => 'active',
        ]);

        Investor::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'first_name' => 'Eliminado',
            'last_name' => 'Preview',
            'name' => 'Eliminado Preview',
            'email' => 'eliminado.preview@orvix.test',
            'phone' => '9993334444',
            'initial_capital' => '75000.00',
            'available_capital' => '75000.00',
            'status' => 'deleted',
        ]);

        $this->actingAs($admin)
            ->post(route('loans.quote-rounded'), [
                'calculation_method' => 'rounded',
                'first_name' => 'Cliente Preview',
                'operator_id' => $operator->id,
                'capital' => '75000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'administration_fee' => '300',
                'vat_enabled' => '0',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 36,
                'payment_day' => 1,
                'start_date' => '2026-08-01',
                'first_payment_date' => '2026-09-01',
            ])
            ->assertOk()
            ->assertSee('Disponible Preview')
            ->assertSee(Money::mxn($availableInvestor->available_capital))
            ->assertDontSee('Eliminado Preview');
    }

    public function test_user_can_upload_document_to_loan_file(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('documents.store', $loan), [
                'name' => 'Factura actualizada',
                'notes' => 'Escaneo nuevo del expediente',
                'file' => UploadedFile::fake()->create('factura.pdf', 512, 'application/pdf'),
            ])
            ->assertRedirect(route('loans.show', $loan));

        $this->assertDatabaseHas('documents', [
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'original_name' => 'FACTURA ACTUALIZADA.pdf',
            'disk' => 'local',
            'notes' => 'ESCANEO NUEVO DEL EXPEDIENTE',
        ]);
    }

    public function test_user_can_download_and_delete_document_from_file(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()->firstOrFail();
        Storage::disk('local')->put('expedientes/demo/prueba.pdf', 'contenido demo');
        $document = Document::query()->create([
            'public_id' => (string) str()->ulid(),
            'loan_id' => $loan->id,
            'client_id' => $loan->client_id,
            'uploaded_by' => $admin->id,
            'original_name' => 'prueba.pdf',
            'disk' => 'local',
            'path' => 'expedientes/demo/prueba.pdf',
            'mime_type' => 'application/pdf',
            'size' => 13,
            'status' => 'delivered',
        ]);

        $this->actingAs($admin)
            ->get(route('documents.download', $document))
            ->assertOk();

        $this->actingAs($admin)
            ->delete(route('documents.destroy', $document))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('expedientes/demo/prueba.pdf');
    }

    public function test_document_search_finds_files_globally(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('documents.index', ['q' => 'contrato']))
            ->assertOk()
            ->assertSee('contrato')
            ->assertSee('Archivos de expedientes');
    }

    public function test_simulator_accepts_annual_rate_and_opening_fee(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('simulator.index', [
                'client_name' => 'KAREN HUERTA',
                'operator_id' => $operator->id,
                'capital' => 150000,
                'rate_type' => 'annual',
                'rate_value' => 24,
                'interest_calculation_method' => 'outstanding_balance',
                'term_months' => 48,
                'start_date' => '2026-07-31',
                'payment_day' => 15,
                'opening_fee_type' => 'percent',
                'opening_fee_value' => 3,
            ]))
            ->assertOk()
            ->assertSee('Comision')
            ->assertSee('$4,500.00');

        $simulation = Simulation::query()->latest('id')->firstOrFail();

        $this->assertSame('annual', $simulation->rate_type);
        $this->assertSame('outstanding_balance', $simulation->interest_calculation_method);
        $this->assertSame(450000, Money::cents($simulation->opening_fee_amount));
    }

    public function test_operator_creates_application_without_rate(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();

        $this->actingAs($samuel)
            ->post(route('applications.store'), [
                'first_name' => 'Karen',
                'last_name' => 'Huerta',
                'phone' => '9995550101',
                'email' => 'karen@example.test',
                'requested_capital' => '150000',
                'term_months' => 48,
                'payment_day' => 15,
                'notes' => 'Cliente pide revision',
            ])
            ->assertRedirect();

        $application = LoanApplication::query()->latest('id')->firstOrFail();

        $this->assertSame('submitted', $application->status);
        $this->assertSame(0, Money::cents($application->monthly_rate));
        $this->assertSame($samuel->operatorProfile->id, $application->operator_id);
    }

    public function test_admin_simulates_and_approves_application_conditions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();

        $this->actingAs($samuel)->post(route('applications.store'), [
            'first_name' => 'Laura',
            'last_name' => 'Pech',
            'phone' => '9995550202',
            'requested_capital' => '100000',
            'term_months' => 24,
            'payment_day' => 12,
        ]);

        $application = LoanApplication::query()->latest('id')->firstOrFail();

        $conditions = [
            'capital' => '95000',
            'term_months' => 18,
            'payment_day' => 10,
            'start_date' => '2026-08-01',
            'rate_type' => 'annual',
            'rate_value' => 24,
            'interest_calculation_method' => 'outstanding_balance',
            'opening_fee_type' => 'percent',
            'opening_fee_value' => 3,
        ];

        $this->actingAs($admin)
            ->post(route('applications.simulate', $application), $conditions)
            ->assertSessionHas('status');

        $application->refresh();

        $this->assertSame('submitted', $application->status);
        $this->assertSame('0.020000', $application->approved_conditions['monthly_rate']);
        $this->assertSame('outstanding_balance', $application->approved_conditions['interest_calculation_method']);
        $this->assertSame('2850.00', $application->approved_conditions['opening_fee_amount']);

        $this->actingAs($admin)
            ->post(route('applications.approve', $application), $conditions)
            ->assertSessionHas('status');

        $application->refresh();

        $this->assertSame('approved', $application->status);
        $this->assertSame(18, $application->approved_conditions['term_months']);
    }

    public function test_admin_can_edit_user_role_permissions_and_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $target = User::query()->where('email', 'adriana@orvix.test')->firstOrFail();
        $permission = Permission::query()->where('name', 'reports.view-all')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('settings.users.update', $target), [
                'name' => 'Adriana Expedientes',
                'email' => 'adriana.edita@orvix.test',
                'password' => 'NuevaClave123',
                'role' => 'administrador-general',
                'permissions' => [$permission->name],
            ])
            ->assertSessionHas('status');

        $target->refresh();

        $this->assertSame('ADRIANA EXPEDIENTES', $target->name);
        $this->assertSame('adriana.edita@orvix.test', $target->email);
        $this->assertTrue(Hash::check('NuevaClave123', $target->password));
        $this->assertTrue($target->force_password_change);
        $this->assertTrue($target->hasRole('administrador-general'));
        $this->assertTrue($target->hasDirectPermission($permission->name));
    }

    public function test_admin_can_delete_another_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $target = User::query()->create([
            'name' => 'Usuario Temporal',
            'email' => 'temporal@orvix.test',
            'password' => 'orvix-demo',
            'status' => 'active',
        ]);
        $target->assignRole('responsable-documental');

        $this->actingAs($admin)
            ->delete(route('settings.users.destroy', $target))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
        ]);
    }

    public function test_admin_cannot_delete_current_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('settings.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
        ]);
    }

    public function test_admin_can_edit_role_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $role = Role::query()->where('name', 'responsable-documental')->firstOrFail();
        $permission = Permission::query()->where('name', 'reports.view-all')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('settings.roles.update', $role), [
                'name' => 'responsable-documental-plus',
                'permissions' => [$permission->name],
            ])
            ->assertSessionHas('status');

        $role->refresh();

        $this->assertSame('responsable-documental-plus', $role->name);
        $this->assertTrue($role->hasPermissionTo($permission->name));
    }

    public function test_payments_are_assigned_to_official_cut_periods_when_cut_is_generated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $installments = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->take(2)
            ->get();

        Carbon::setTestNow('2026-08-13 23:30:00');
        CarbonImmutable::setTestNow('2026-08-13 23:30:00');
        $this->actingAs($samuel)->post(route('collections.mark-paid', $installments[0]), [
            'operated_on' => '2026-08-01',
            'contract_amount' => $installments[0]->remaining_amount,
            'operator_surcharge_amount' => 0,
            'external_concepts_amount' => 0,
        ]);

        $thursdayMovement = CollectionMovement::query()->where('target_installment_id', $installments[0]->id)->firstOrFail();
        $this->assertNull($thursdayMovement->weekly_cut_id);

        $this->actingAs($samuel)->post(route('cuts.store'), ['cut_date' => '2026-08-13']);
        $thursdayMovement->refresh();
        $this->assertSame('2026-08-13', $thursdayMovement->weeklyCut->period_starts_on->toDateString());
        $this->assertSame('2026-08-13', $thursdayMovement->weeklyCut->period_ends_on->toDateString());
        $this->assertSame('2026-08-13', $thursdayMovement->weeklyCut->settlement_on->toDateString());

        Carbon::setTestNow('2026-08-14 00:01:00');
        CarbonImmutable::setTestNow('2026-08-14 00:01:00');
        $this->actingAs($samuel)->post(route('collections.mark-paid', $installments[1]), [
            'operated_on' => '2026-08-01',
            'contract_amount' => $installments[1]->remaining_amount,
            'operator_surcharge_amount' => 0,
            'external_concepts_amount' => 0,
        ]);

        $fridayMovement = CollectionMovement::query()->where('target_installment_id', $installments[1]->id)->firstOrFail();
        $this->assertNull($fridayMovement->weekly_cut_id);

        $this->actingAs($samuel)->post(route('cuts.store'), ['cut_date' => '2026-08-14']);
        $fridayMovement->refresh();
        $this->assertSame('2026-08-14', $fridayMovement->weeklyCut->period_starts_on->toDateString());
        $this->assertSame('2026-08-14', $fridayMovement->weeklyCut->period_ends_on->toDateString());
        $this->assertSame('2026-08-14', $fridayMovement->weeklyCut->settlement_on->toDateString());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_declared_payment_date_and_confirmation_do_not_move_cut_assignment(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-10 12:00:00');
        CarbonImmutable::setTestNow('2026-08-10 12:00:00');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)->post(route('collections.mark-paid', $installment), [
            'operated_on' => '2026-07-01',
            'contract_amount' => $installment->remaining_amount,
            'operator_surcharge_amount' => 0,
            'external_concepts_amount' => 0,
        ]);

        $movement = CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail();
        $this->assertNull($movement->weekly_cut_id);

        $this->actingAs($admin)->post(route('cuts.store'), [
            'operator_id' => $samuel->operatorProfile->id,
            'cut_date' => '2026-08-10',
        ]);
        $movement->refresh();
        $cutId = $movement->weekly_cut_id;
        $this->assertNotNull($cutId);

        $movement->update(['operated_on' => '2026-06-01']);
        $this->assertSame($cutId, $movement->fresh()->weekly_cut_id);

        $this->actingAs($admin)->post(route('payments.confirm', $movement))->assertSessionHas('status');
        $this->assertSame($cutId, $movement->fresh()->weekly_cut_id);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_operator_cannot_mark_overdue_from_cut_adjustment_action(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $cut = WeeklyCut::query()->where('operator_id', $samuel->operatorProfile->id)->firstOrFail();
        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($samuel)
            ->post(route('collections.mark-paid', $installment), [
                'return_to' => 'cut',
                'cut_id' => $cut->id,
                'operated_on' => now('America/Merida')->toDateString(),
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
            ])
            ->assertForbidden();
    }

    public function test_admin_marking_overdue_from_cut_registers_payment_in_that_cut(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-10 12:00:00');
        CarbonImmutable::setTestNow('2026-08-10 12:00:00');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $cut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->where('status', 'submitted')
            ->firstOrFail();
        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->whereDate('due_date', '<', $cut->period_starts_on->toDateString())
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('collections.mark-paid', $installment), [
                'return_to' => 'cut',
                'cut_id' => $cut->id,
                'operated_on' => '2026-07-30',
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
                'notes' => 'Ajuste desde atrasados del corte',
            ])
            ->assertRedirect(route('cuts.show', $cut));

        $movement = CollectionMovement::query()
            ->where('target_installment_id', $installment->id)
            ->firstOrFail();

        $this->assertSame($cut->id, $movement->weekly_cut_id);
        $this->assertTrue(WeeklyCutItem::query()
            ->where('weekly_cut_id', $cut->id)
            ->where('collection_movement_id', $movement->id)
            ->exists());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_open_cut_can_be_updated_after_reception_until_it_is_closed(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-10 12:00:00');
        CarbonImmutable::setTestNow('2026-08-10 12:00:00');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $cut = WeeklyCut::query()
            ->where('operator_id', $samuel->operatorProfile->id)
            ->where('status', 'submitted')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('cuts.confirm', $cut), [
                'received_total' => $cut->reported_total,
                'received_on' => '2026-08-10',
                'reason' => 'Primera recepcion',
            ])
            ->assertSessionHas('status');

        $installment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->whereDate('due_date', '<', $cut->period_starts_on->toDateString())
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('collections.mark-paid', $installment), [
                'return_to' => 'cut',
                'cut_id' => $cut->id,
                'operated_on' => '2026-07-30',
                'contract_amount' => $installment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
            ])
            ->assertRedirect(route('cuts.show', $cut));

        $cut->refresh();
        $this->actingAs($admin)
            ->post(route('cuts.confirm', $cut), [
                'received_total' => $cut->reported_total,
                'received_on' => '2026-08-10',
                'reason' => 'Recepcion actualizada',
            ])
            ->assertSessionHas('status');

        $this->assertSame(1, OperatorLedgerEntry::query()
            ->where('weekly_cut_id', $cut->id)
            ->whereIn('type', ['confirmed_delivery', 'shortfall', 'overage'])
            ->count());
        $this->assertSame(Money::cents($cut->fresh()->reported_total), Money::cents($cut->fresh()->received_total));
        $this->assertSame('applied', CollectionMovement::query()->where('target_installment_id', $installment->id)->firstOrFail()->confirmation_status);

        $this->actingAs($admin)->post(route('cuts.close', $cut))->assertSessionHas('status');

        $anotherInstallment = Installment::query()
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $samuel->operatorProfile->id))
            ->where('remaining_amount', '>', 0)
            ->whereDoesntHave('reportedMovement')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('collections.mark-paid', $anotherInstallment), [
                'return_to' => 'cut',
                'cut_id' => $cut->id,
                'operated_on' => '2026-07-30',
                'contract_amount' => $anotherInstallment->remaining_amount,
                'operator_surcharge_amount' => 0,
                'external_concepts_amount' => 0,
            ])
            ->assertStatus(422);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_loan_created_from_cut_records_fund_disbursement_link(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-10 12:00:00');
        CarbonImmutable::setTestNow('2026-08-10 12:00:00');

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();
        $investor = Investor::query()->where('available_capital', '>=', 50000)->firstOrFail();

        $this->actingAs($admin)->post(route('cuts.store'), ['operator_id' => $operator->id]);
        $cut = WeeklyCut::query()->where('operator_id', $operator->id)->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('loans.store'), [
                'first_name' => 'Cliente Corte',
                'operator_id' => $operator->id,
                'capital' => '50000',
                'rate_type' => 'monthly',
                'rate_value' => '2',
                'administration_fee' => '0',
                'vat_enabled' => '1',
                'interest_calculation_method' => 'fixed_principal',
                'term_months' => 12,
                'start_date' => '2026-08-10',
                'first_payment_date' => '2026-09-10',
                'payment_day' => 10,
                'weekly_cut_id' => $cut->id,
                'disbursement_delivered_on' => '2026-08-10',
                'investors' => [
                    ['investor_id' => $investor->id, 'capital_amount' => '50000', 'interest_share_percent' => 100],
                ],
            ])
            ->assertSessionHas('status');

        $loan = Loan::query()->whereHas('client', fn ($query) => $query->where('first_name', 'CLIENTE CORTE'))->firstOrFail();
        $disbursement = FundDisbursement::query()->where('loan_id', $loan->id)->firstOrFail();

        $this->assertSame($cut->id, $disbursement->weekly_cut_id);
        $this->assertSame($loan->operator_id, $disbursement->operator_id);
        $this->assertSame(5000000, Money::cents($disbursement->amount));
        $this->assertSame(5000000, Money::cents($cut->fresh()->funds_delivered_total));

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
