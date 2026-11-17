<?php

namespace Tests\Feature;

use App\Domain\Loans\LoanFormalizer;
use App\Models\Client;
use App\Models\Operator;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CajasV1ClientNameSearchTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://prestamos.example.test/api/v1/cajas/pagares';

    private Operator $operator;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (! (DB::connection() instanceof MySqlConnection)) {
            $this->markTestSkipped('El catálogo productivo se verifica con MariaDB aislada.');
        }
        $this->travelTo(now('UTC')->setDate(2026, 11, 17)->setTime(18, 0));
        $this->app->instance('env', 'production');
        $this->operator = Operator::create(['public_id' => (string) Str::ulid(), 'name' => 'Operador ficticio de búsqueda', 'status' => 'active']);
        $this->token = bin2hex(random_bytes(32));
        config([
            'app.debug' => false,
            'cajas.v1_production.enabled' => true,
            'cajas.v1_production.admissions' => false,
            'cajas.v1_production.database' => DB::connection()->getDatabaseName(),
            'cajas.v1_production.public_url' => 'https://prestamos.example.test',
            'cajas.v1_production.approval_url' => 'https://cajas.example.test',
            'cajas.v1_production.trusted_proxies' => [],
            'cajas.v1_production.tokens.read' => $this->token,
            'cajas.v1_production.tokens.write' => bin2hex(random_bytes(32)),
            'cajas.v1_production.read_all_active_operators' => true,
            'cajas.v1_production.write_operators' => [$this->operator->public_id],
            'cajas.v1_production.cutover_on' => '2026-11-12',
        ]);
    }

    public function test_full_name_fragments_accents_case_empty_and_zero_keep_rows_and_finances(): void
    {
        $maria = $this->fixture('María José', 'López Pérez');
        $zero = $this->fixture('0', 'Cliente');
        $before = $this->financialState();
        $normal = $this->catalog()->assertOk()->json();
        foreach (['María José López Pérez', 'maria jose lopez perez', '  JOSÉ LÓPEZ  ', 'lopez', 'ía José'] as $fragment) {
            $rows = $this->catalog(['client_name' => $fragment])->assertOk()->json('data');
            $this->assertCount(6, $rows);
            $this->assertSame([$maria['loan']->public_id], array_values(array_unique(array_column($rows, 'loan_id'))));
            $original = array_values(array_filter($normal['data'], fn ($r) => $r['loan_id'] === $maria['loan']->public_id));
            $this->assertSame($original, $rows);
        }
        $this->catalog(['client_name' => 'José  López'])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'Pérez María'])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'inexistente'])->assertOk()->assertJsonCount(0, 'data');
        foreach (['', '   '] as $empty) {
            $this->assertSame($normal, $this->catalog(['client_name' => $empty])->assertOk()->json());
        }
        $this->catalog(['client_name' => '0'])->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.loan_id', $zero['loan']->public_id);
        $this->assertSame($before, $this->financialState());
    }

    public function test_percent_underscore_escape_and_quotes_are_literal_bound_fragments(): void
    {
        $percent = $this->fixture('Cliente 100%', 'Prueba');
        $underscore = $this->fixture('Cliente_Exacto', 'Prueba');
        $equals = $this->fixture('Cliente=Exacto', 'Prueba');
        $apostrophe = $this->fixture("D'Ávila", 'Prueba');
        $this->fixture('Cliente 1001', 'Prueba');
        $before = $this->financialState();
        foreach (['%' => $percent, '_' => $underscore, '=' => $equals, "d'avila" => $apostrophe] as $fragment => $fixture) {
            $this->catalog(['client_name' => $fragment])->assertOk()->assertJsonCount(6, 'data')
                ->assertJsonPath('data.0.loan_id', $fixture['loan']->public_id);
        }
        $this->catalog(['client_name' => "' OR 1=1 --"])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($before, $this->financialState());
    }

    public function test_name_is_anded_with_folio_day_model_exact_id_and_operator_scope(): void
    {
        $wanted = $this->fixture('María', 'López');
        $otherClient = $this->fixture('Pedro', 'Ramírez');
        $otherOperator = Operator::create(['public_id' => (string) Str::ulid(), 'name' => 'Otro operador ficticio', 'status' => 'active']);
        $other = $this->fixture('María', 'López', $otherOperator);
        $before = $this->financialState();
        $this->catalog(['client_name' => 'maria', 'folio' => $wanted['loan']->folio, 'day' => 12])->assertOk()->assertJsonCount(6, 'data');
        $this->catalog(['client_name' => 'maria', 'folio' => $otherClient['loan']->folio])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'maria', 'day' => 13])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'maria', 'model' => 'modelo-inexistente'])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'maria', 'installment_id' => $wanted['letter']->public_id])->assertOk()->assertJsonCount(1, 'data');
        $this->catalog(['client_name' => 'pedro', 'installment_id' => $wanted['letter']->public_id])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'maria', 'installment_id' => $other['letter']->public_id])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => 'maria', 'cut_on' => '2026-11-11'])->assertOk()->assertJsonCount(0, 'data');
        config(['cajas.v1_production.write_operators' => [$this->operator->public_id, $otherOperator->public_id]]);
        $this->catalog(['client_name' => 'maria'], $otherOperator)->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.loan_id', $other['loan']->public_id);
        config(['cajas.v1_production.read_all_active_operators' => false, 'cajas.v1_production.write_operators' => [$this->operator->public_id]]);
        $this->catalog(['client_name' => 'maria'], $otherOperator)->assertForbidden()->assertJsonPath('error.code', 'OPERATOR_OUT_OF_SCOPE');
        config(['cajas.v1_production.read_all_active_operators' => true]);
        $otherOperator->update(['status' => 'inactive']);
        $stateAfterControlledSetup = $this->financialState();
        $this->catalog(['client_name' => 'maria'], $otherOperator)->assertForbidden()->assertJsonPath('error.code', 'OPERATOR_OUT_OF_SCOPE');
        $this->withToken('invalid-credential')->getJson(self::API.'?'.http_build_query([
            'operator' => $this->operator->public_id, 'cut_on' => '2026-11-12', 'client_name' => 'maria',
        ]))->assertUnauthorized();
        $this->assertSame($before, $stateAfterControlledSetup);
        $this->assertSame($before, $this->financialState());
    }

    public function test_client_filter_precedes_pagination_and_excludes_ineligible_letters(): void
    {
        $this->fixture('Otra', 'Persona', term: 30);
        $wantedIds = [];
        for ($i = 0; $i < 4; $i++) {
            $fixture = $this->fixture('María José', 'López Pérez', term: 30);
            array_push($wantedIds, ...$fixture['loan']->installments()->pluck('public_id')->all());
        }
        $frozen = $this->fixture('María José', 'López Pérez');
        $frozen['loan']->update(['is_frozen' => true]);
        $before = $this->financialState();
        $seen = [];
        foreach ([1 => 50, 2 => 50, 3 => 20, 4 => 0] as $page => $count) {
            $response = $this->catalog(['client_name' => 'maria jose', 'page' => $page])->assertOk()->assertJsonCount($count, 'data')
                ->assertJsonPath('has_more', $page < 3);
            array_push($seen, ...array_column($response->json('data'), 'installment_id'));
        }
        $this->assertEqualsCanonicalizing($wantedIds, $seen);
        $this->assertCount(120, array_unique($seen));
        $this->assertSame($before, $this->financialState());
    }

    public function test_validation_and_replaced_letter_history_keep_the_client_filter(): void
    {
        $fixture = $this->fixture('María', 'López');
        $oldId = (string) Str::ulid();
        DB::table('cajas_v1_installment_replacements')->insert([
            'public_id' => $oldId, 'loan_public_id' => $fixture['loan']->public_id,
            'replacement_public_id' => $fixture['letter']->public_id,
        ]);
        $before = $this->financialState();
        $this->catalog(['client_name' => 'maria', 'installment_id' => $oldId])->assertOk()
            ->assertJsonPath('data.0.status', 'replaced')->assertJsonPath('data.0.replacement_installment_ids', [$fixture['letter']->public_id]);
        $this->catalog(['client_name' => 'otro cliente', 'installment_id' => $oldId])->assertOk()->assertJsonCount(0, 'data');
        $this->catalog(['client_name' => str_repeat('á', 151)])->assertStatus(422);
        $this->catalog(['client_name' => ['María']])->assertStatus(422);
        $this->catalog(['client_name' => str_repeat('á', 150)])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($before, $this->financialState());
    }

    private function fixture(string $first, string $last, ?Operator $operator = null, int $term = 6): array
    {
        $operator ??= $this->operator;
        $client = Client::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $operator->id,
            'first_name' => $first, 'last_name' => $last, 'phone' => '0000000000',
        ]);
        $loan = app(LoanFormalizer::class)->create($client, [
            'operator_id' => $operator->id, 'capital' => '12000', 'monthly_rate' => '0.02', 'term_months' => $term,
            'payment_day' => 12, 'start_date' => '2026-09-12', 'first_payment_date' => '2026-10-12', 'vat_enabled' => false,
        ]);
        $letter = $loan->installments()->where('number', 1)->firstOrFail();

        return compact('client', 'loan', 'letter');
    }

    private function catalog(array $filters = [], ?Operator $operator = null): TestResponse
    {
        return $this->withToken($this->token)->getJson(self::API.'?'.http_build_query([
            'operator' => ($operator ?? $this->operator)->public_id, 'cut_on' => '2026-11-12', ...$filters,
        ]));
    }

    private function financialState(): array
    {
        $state = [];
        foreach ([
            'clients', 'loans', 'installments', 'collection_movements', 'payment_allocations', 'investors', 'investments',
            'investor_capital_movements', 'investment_ledger_entries', 'operator_ledger_entries', 'fund_disbursements',
            'cajas_v1_cut_executions', 'cajas_v1_custody_openings', 'cajas_v1_custody_movements',
        ] as $table) {
            $state[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $state;
    }
}
