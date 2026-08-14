<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_invoice_portfolio_and_filter_by_holder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@orvix.test')->firstOrFail();
        $loan = Loan::query()->where('status', 'active')->firstOrFail();
        $loan->update(['invoice_holder' => 'Caja']);

        $this->actingAs($admin)
            ->get(route('invoice-portfolio.index', ['holder' => 'caja']))
            ->assertOk()
            ->assertSee('Facturas')
            ->assertSee($loan->folio)
            ->assertSee('Caja');
    }
}
