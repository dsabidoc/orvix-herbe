<?php

namespace Tests\Feature;

use App\Models\Operator;
use App\Models\User;
use App\Models\WeeklyCut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WeeklyCutStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $name): Operator
    {
        return Operator::query()->create(['public_id' => (string) Str::ulid(), 'name' => $name]);
    }

    private function cut(Operator $operator, string $status, string $date = '2026-10-19'): WeeklyCut
    {
        return WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $operator->id,
            'period_starts_on' => $date, 'period_ends_on' => $date,
            'status' => $status, 'reported_total' => '100.00',
            'received_total' => '75.00', 'confirmed_total' => '75.00',
            'confirmed_at' => $date.' 23:59:59',
        ]);
    }

    public function test_filter_matches_visible_status_groups_and_their_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $operator = $this->operator('DARIO');
        foreach (['draft', 'forming', 'submitted', 'pending_reconciliation', 'reopened',
            'partially_received', 'reviewing', 'confirmed', 'balanced', 'with_difference',
            'closed', 'returned', 'voided'] as $status) {
            $this->cut($operator, $status);
        }

        foreach ([
            'pending' => ['draft', 'forming', 'submitted', 'pending_reconciliation', 'reopened'],
            'reviewing' => ['partially_received', 'reviewing', 'confirmed', 'balanced', 'with_difference'],
            'closed' => ['closed'], 'returned' => ['returned'], 'voided' => ['voided'],
        ] as $filter => $statuses) {
            $count = count($statuses);
            $this->get(route('cuts.index', ['status' => $filter]))->assertOk()
                ->assertViewHas('selectedStatus', $filter)
                ->assertViewHas('cuts', fn ($cuts) => $cuts->total() === $count && $cuts->every(fn ($cut) => in_array($cut->status, $statuses, true)))
                ->assertViewHas('summary', fn ($summary) => $summary['cuts'] === $count
                    && $summary['reported_cents'] === 10000 * $count
                    && $summary['received_cents'] === 7500 * $count)
                ->assertSee('Todos los estados');
        }

        $this->get(route('cuts.index'))->assertViewHas('summary', fn ($summary) => $summary['cuts'] === 13);
        $this->get(route('cuts.index', ['status' => 'unknown']))->assertOk()
            ->assertViewHas('selectedStatus', null)
            ->assertViewHas('summary', fn ($summary) => $summary['cuts'] === 13);
    }

    public function test_status_combines_with_month_operator_and_pagination_without_changing_records(): void
    {
        $this->actingAs(User::factory()->create());
        $operator = $this->operator('DARIO');
        for ($cut = 0; $cut < 17; $cut++) {
            $this->cut($operator, 'closed');
        }
        $this->cut($operator, 'partially_received');
        $this->cut($operator, 'closed', '2026-09-19');
        $this->cut($this->operator('OTRO'), 'closed');
        $before = WeeklyCut::query()->orderBy('id')->get()->toArray();
        $filters = ['status' => 'closed', 'month' => '2026-10', 'operator_id' => $operator->id];

        $this->get(route('cuts.index', $filters))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['cuts'] === 17 && $summary['operators'] === 1
                && $summary['reported_cents'] === 170000 && $summary['received_cents'] === 127500)
            ->assertViewHas('cuts', fn ($cuts) => $cuts->total() === 17 && $cuts->count() === 15
                && str_contains($cuts->nextPageUrl(), 'status=closed')
                && str_contains($cuts->nextPageUrl(), 'month=2026-10')
                && str_contains($cuts->nextPageUrl(), 'operator_id='.$operator->id));
        $this->get(route('cuts.index', [...$filters, 'page' => 2]))->assertOk()
            ->assertViewHas('cuts', fn ($cuts) => $cuts->count() === 2);
        $this->get(route('cuts.index', [...$filters, 'status' => 'voided']))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['cuts'] === 0 && $summary['received_cents'] === 0);
        $this->assertSame($before, WeeklyCut::query()->orderBy('id')->get()->toArray());
    }

    public function test_status_filter_preserves_operator_access_scope(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('operador-cartera', 'web'));
        $operator = $this->operator('DARIO');
        $operator->update(['user_id' => $user->id]);
        $other = $this->operator('OTRO');
        $expected = $this->cut($operator, 'partially_received');
        $this->cut($operator, 'closed');
        $this->cut($other, 'partially_received');

        $this->actingAs($user)->get(route('cuts.index', ['status' => 'reviewing', 'operator_id' => $other->id]))
            ->assertOk()->assertViewHas('cuts', fn ($cuts) => $cuts->total() === 1 && $cuts->first()->id === $expected->id)
            ->assertViewHas('summary', fn ($summary) => $summary['cuts'] === 1 && $summary['received_cents'] === 7500);
    }
}
