<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_demo_admin_can_open_dashboard(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this
            ->post(route('login.store'), [
                'email' => 'admin@orvix.test',
                'password' => 'orvix-demo',
            ])
            ->assertRedirect(route('dashboard'));

        $response = $this->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Dashboard');
    }
}
