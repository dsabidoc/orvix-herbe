<?php

namespace Tests\Feature;

use App\Models\Operator;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_export_portfolio_balances(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('portfolio-balances.index', ['cutoff_date' => '2026-08-10', 'mode' => 'complete']))
            ->assertOk()
            ->assertSee('Cartera y saldos')
            ->assertSee('Resumen por operador')
            ->assertSee('Detalle de cartera');

        $this->actingAs($admin)
            ->get(route('portfolio-balances.export', ['cutoff_date' => '2026-08-10']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_operator_cannot_force_another_operator_in_portfolio_balances(): void
    {
        $this->seed(DatabaseSeeder::class);

        $samuel = User::query()->where('email', 'samuel@orvix.test')->firstOrFail();
        $dario = Operator::query()->where('name', 'Dario')->firstOrFail();

        $this->actingAs($samuel)
            ->get(route('portfolio-balances.index', ['operator_id' => $dario->id]))
            ->assertForbidden();
    }
}
