<?php

namespace Tests\Feature;

use App\Models\CollectionMovement;
use App\Models\Document;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\Operator;
use App\Models\Simulation;
use App\Models\User;
use App\Models\WeeklyCut;
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

    public function test_admin_confirmation_applies_reported_payment_to_installments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $movement = CollectionMovement::query()
            ->where('confirmation_status', 'reported')
            ->where('type', 'ordinary')
            ->firstOrFail();

        $before = $movement->loan->installments()->sum('remaining_amount');

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

    public function test_unmarked_installment_rolls_into_next_week_cut_as_overdue(): void
    {
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow('2026-08-04 10:00:00');
        CarbonImmutable::setTestNow('2026-08-04 10:00:00');

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $overdue = Installment::query()
            ->with('loan.client')
            ->whereDate('due_date', '2026-08-01')
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
            ->whereHas('client', fn ($query) => $query->where('first_name', 'Natalia')->where('last_name', 'Canek Moo'))
            ->where('status', 'active')
            ->firstOrFail();

        $this->actingAs($admin)
            ->get(route('loans.index'))
            ->assertOk()
            ->assertSee('3 vencida(s)');

        $this->actingAs($admin)
            ->get(route('loans.show', $loan))
            ->assertOk()
            ->assertSee('3 letra(s) vencida(s)')
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
        $this->assertSame(
            Money::cents($nextCut->reported_total) + 100000,
            Money::cents($nextCut->expected_total),
        );

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
        $this->assertSame(Money::cents($nextCut->reported_total), Money::cents($nextCut->expected_total));

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
            ->assertSessionHasInput('first_name', 'Cliente Minimo');
    }

    public function test_admin_can_create_loan_with_minimum_client_and_conditions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $operator = Operator::query()->firstOrFail();

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
            ])
            ->assertRedirect();

        $loan = Loan::query()
            ->whereHas('client', fn ($query) => $query->where('first_name', 'Cliente Minimo'))
            ->firstOrFail();

        $this->assertSame('outstanding_balance', $loan->interest_calculation_method);
        $this->assertSame(0.02, (float) $loan->monthly_rate);
        $this->assertSame('Sin marca', $loan->vehicle->brand);
        $this->assertSame('Vehiculo', $loan->vehicle->model);
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
            'original_name' => 'Factura actualizada.pdf',
            'disk' => 'local',
            'notes' => 'Escaneo nuevo del expediente',
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

        $this->assertSame('Adriana Expedientes', $target->name);
        $this->assertSame('adriana.edita@orvix.test', $target->email);
        $this->assertTrue(Hash::check('NuevaClave123', $target->password));
        $this->assertTrue($target->force_password_change);
        $this->assertTrue($target->hasRole('administrador-general'));
        $this->assertTrue($target->hasDirectPermission($permission->name));
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
}
