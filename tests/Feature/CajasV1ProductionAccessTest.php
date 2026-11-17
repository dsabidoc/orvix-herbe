<?php

namespace Tests\Feature;

use App\Domain\Cajas\CajasSandbox;
use App\Domain\Cajas\V1\IntegrationAccess;
use App\Domain\Cajas\V1\PilotSignature;
use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CajasV1PendingCredit;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CajasV1ProductionAccessTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://prestamos.example.test/api/v1/cajas';

    private const CATALOG = self::API.'/pagares?operator=operator-fixture&cut_on=2026-11-15';

    private const CAPITAL = self::API.'/capital-disponible?scope=portfolio&currency=MXN';

    private string $readToken;

    private string $writeToken;

    private string $capitalToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        $this->readToken = bin2hex(random_bytes(32));
        $this->writeToken = bin2hex(random_bytes(32));
        $this->capitalToken = bin2hex(random_bytes(32));

        config([
            'app.debug' => false,
            'cajas.v1_production.enabled' => false,
            'cajas.v1_production.trusted_proxies' => [],
            'cajas.v1_production.database' => DB::connection()->getDatabaseName(),
            'cajas.v1_production.public_url' => 'https://prestamos.example.test',
            'cajas.v1_production.approval_url' => 'https://cajas.example.test',
            'cajas.v1_production.tokens.read' => $this->readToken,
            'cajas.v1_production.tokens.write' => $this->writeToken,
            'cajas.v1_production.tokens.capital' => $this->capitalToken,
            'cajas.v1_production.operator' => 'operator-fixture',
            'cajas.v1_production.read_all_active_operators' => false,
            'cajas.v1_production.cutover_on' => '2026-11-15',
            'cajas.v1_pilot.enabled' => true,
            'cajas.v1_pilot.token' => 'isolated-pilot-token',
        ]);
    }

    public function test_production_is_disabled_by_default_and_pilot_flags_cannot_enable_it(): void
    {
        $this->withToken($this->capitalToken)->getJson(self::CAPITAL)->assertNotFound();
        $this->withToken('isolated-pilot-token')->getJson(self::CATALOG)->assertNotFound();
        $this->assertFalse(app(IntegrationAccess::class)->enabled());
        config(['cajas.sandbox_enabled' => true]);
        $this->assertFalse(CajasSandbox::enabled());
    }

    public function test_operation_credentials_and_tls_cannot_be_bypassed_and_pause_keeps_the_capture_channel(): void
    {
        if (! (DB::connection() instanceof MySqlConnection) || DB::connection()->getDatabaseName() === 'prestamos_cajas_v1_pilot') {
            $this->markTestSkipped('Use a separate MariaDB schema for the simulated production profile.');
        }

        config([
            'cajas.v1_production.enabled' => true,
            'cajas.v1_production.admissions' => false,
        ]);
        Http::fake();
        $this->withToken($this->capitalToken)->getJson(self::CAPITAL)->assertOk();
        $this->withToken($this->readToken)->getJson(self::CAPITAL)->assertUnauthorized();

        $this->withToken($this->capitalToken)->withHeader('X-Forwarded-Proto', 'https')
            ->getJson(str_replace('https:', 'http:', self::CAPITAL))->assertNotFound();
        $this->getJson(str_replace('prestamos.example.test', 'other.example.test', self::CAPITAL))->assertNotFound();

        $data = ['cut_id' => 'cut-fixture', 'version' => 1, 'operator' => 'operator-fixture'];
        $this->withHeader('Idempotency-Key', 'cut:cut-fixture:v1');
        $this->withToken($this->readToken)->postJson(self::API.'/cortes', $data)->assertUnauthorized();
        $this->withToken($this->writeToken)->postJson(self::API.'/cortes', $data)
            ->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_PAUSED');
        $this->postJson(self::API.'/cortes', [...$data, 'operator' => 'other-operator'])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'correction:correction-fixture')
            ->postJson(self::API.'/cortes/cut-fixture/correcciones', [
                'correction_id' => 'correction-fixture', 'operator' => 'operator-fixture',
            ])->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_PAUSED');
        config(['cajas.v1_production.custody_accounts' => ['cash-fixture']]);
        $this->withHeader('Idempotency-Key', 'custody:movement-fixture')
            ->postJson(self::API.'/confirmaciones-custodia', [
                'cash_movement_id' => 'movement-fixture', 'cash_account_id' => 'cash-fixture',
            ])->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_PAUSED');
        Http::assertNothingSent();
        $this->assertTrue(app(IntegrationAccess::class)->usesCajas('operator-fixture', '2026-11-15'));
        $this->assertFalse(app(IntegrationAccess::class)->usesCajas('other-operator', '2026-11-15'));

        config(['cajas.v1_production.tokens.capital' => $this->readToken]);
        $this->withToken($this->readToken)->getJson(self::CAPITAL)->assertUnauthorized();
    }

    public function test_rotation_expires_and_pilot_secrets_cannot_be_promoted(): void
    {
        $this->freezeTime();
        $active = sodium_crypto_sign_keypair();
        $previous = sodium_crypto_sign_keypair();
        $pilot = sodium_crypto_sign_keypair();
        $previousToken = bin2hex(random_bytes(32));
        config([
            'cajas.v1_production.approval_public_key' => base64_encode(sodium_crypto_sign_publickey($active)),
            'cajas.v1_production.previous_approval_public_key' => base64_encode(sodium_crypto_sign_publickey($previous)),
            'cajas.v1_production.previous_tokens.read' => $previousToken,
            'cajas.v1_production.rotation_until' => now()->utc()->addMinute()->format('Y-m-d\TH:i:s\Z'),
            'cajas.v1_pilot.approval_public_key' => base64_encode(sodium_crypto_sign_publickey($pilot)),
        ]);

        $verifier = app(PilotSignature::class);
        $access = app(IntegrationAccess::class);
        $this->assertSame($previousToken, $access->validToken('read', previous: true));
        $this->assertNotNull($verifier->claims($this->proof($previous)));
        $this->assertNotNull($verifier->claims($this->proof($active)));
        $this->assertNull($verifier->claims($this->proof($pilot)));
        $this->travel(2)->minutes();
        $this->assertNull($access->validToken('read', previous: true));
        $this->assertNull($verifier->claims($this->proof($previous)));
        $this->assertNotNull($verifier->claims($this->proof($active)));

        config([
            'cajas.v1_production.tokens.read' => $this->readToken,
            'cajas.v1_pilot.token' => $this->readToken,
            'cajas.v1_production.approval_public_key' => config('cajas.v1_pilot.approval_public_key'),
        ]);
        $this->assertNull($access->validToken('read'));
        $this->assertNull($verifier->claims($this->proof($pilot)));
    }

    public function test_all_active_operator_queries_preserve_balances_and_recheck_status(): void
    {
        if (! (DB::connection() instanceof MySqlConnection)) {
            $this->markTestSkipped('El perfil productivo requiere una base MariaDB aislada.');
        }

        $this->freezeTime();
        $fixtures = [];
        foreach (range(1, 5) as $number) {
            $fixtures[] = $this->operatorReadFixture($number);
        }
        $inactive = $this->operatorReadFixture(6);
        $inactive['operator']->update(['status' => 'inactive']);
        config([
            'cajas.v1_production.enabled' => true,
            'cajas.v1_production.admissions' => false,
            'cajas.v1_production.operator' => $fixtures[0]['operator']->public_id,
            'cajas.v1_production.cutover_on' => '',
            'cajas.v1_production.read_all_active_operators' => true,
        ]);
        Http::preventStrayRequests();
        $state = $this->financialState();
        foreach ($fixtures as $fixture) {
            $this->assertOperatorReads($fixture);
            $this->assertFalse(app(IntegrationAccess::class)->usesCajas($fixture['operator']->public_id, '2026-11-15'));
        }
        $this->withToken($this->capitalToken)->getJson(self::CAPITAL)->assertOk();
        $this->assertOperatorReads($inactive, forbidden: true);
        $this->assertSame($state, $this->financialState());

        $fixtures[1]['operator']->update(['status' => 'inactive']);
        $this->assertOperatorReads($fixtures[1], forbidden: true);
        $inactive['operator']->update(['status' => 'active']);
        $this->assertOperatorReads($inactive);
        $this->assertSame($state, $this->financialState());

        config(['cajas.v1_production.read_all_active_operators' => false]);
        $this->assertOperatorReads($fixtures[0]);
        $this->assertOperatorReads($fixtures[2], forbidden: true);
        $this->assertSame($state, $this->financialState());
        Http::assertNothingSent();
    }

    public function test_multioperator_read_scope_does_not_expand_writes_or_bypass_credentials(): void
    {
        if (! (DB::connection() instanceof MySqlConnection)) {
            $this->markTestSkipped('El perfil productivo requiere una base MariaDB aislada.');
        }

        $first = $this->operatorReadFixture(1);
        $second = $this->operatorReadFixture(2);
        config([
            'cajas.v1_production.enabled' => true,
            'cajas.v1_production.admissions' => false,
            'cajas.v1_production.operator' => $first['operator']->public_id,
            'cajas.v1_production.cutover_on' => '',
            'cajas.v1_production.read_all_active_operators' => true,
        ]);
        Http::preventStrayRequests();
        $state = $this->financialState();
        $catalog = self::API.'/pagares?'.http_build_query([
            'operator' => $second['operator']->public_id, 'cut_on' => '2026-11-15',
            'installment_id' => $second['letter']->public_id,
        ]);
        $this->withToken($this->capitalToken)->getJson($catalog)->assertUnauthorized();
        $this->withToken($this->writeToken)->getJson($catalog)->assertUnauthorized();
        $this->withToken('invalid-production-credential')->getJson($catalog)->assertUnauthorized();
        $this->withToken($this->readToken)->getJson(str_replace('https:', 'http:', $catalog))->assertNotFound();
        $this->getJson(str_replace('prestamos.example.test', 'other.example.test', $catalog))->assertNotFound();
        $this->assertOperatorReads($second);

        foreach ([$first, $second] as $index => $fixture) {
            $cut = ['cut_id' => 'new-cut-'.$index, 'version' => 1, 'operator' => $fixture['operator']->public_id];
            $code = $index === 0 ? 'INTEGRATION_PAUSED' : 'OPERATOR_OUT_OF_SCOPE';
            $status = $index === 0 ? 503 : 403;
            $this->withToken($this->writeToken)->withHeader('Idempotency-Key', 'cut:'.$cut['cut_id'].':v1')
                ->postJson(self::API.'/cortes', $cut)->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->withHeader('Idempotency-Key', 'correction:new-correction-'.$index)
                ->postJson(self::API.'/cortes/'.$cut['cut_id'].'/correcciones', [
                    'correction_id' => 'new-correction-'.$index, 'operator' => $cut['operator'],
                ])->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->withHeader('Idempotency-Key', 'funding:new-funding-'.$index)
                ->postJson(self::API.'/creditos-pendientes/'.$fixture['credit']->public_id.'/fondeo', [
                    'operator' => $cut['operator'], 'event_id' => 'new-funding-'.$index,
                ])->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->assertFalse(app(IntegrationAccess::class)->usesCajas($cut['operator'], '2026-11-15'));
        }
        $this->assertSame([$first['operator']->public_id], app(IntegrationAccess::class)->operators());
        config(['cajas.v1_production.cutover_on' => '2026-11-15']);
        $this->assertTrue(app(IntegrationAccess::class)->usesCajas($first['operator']->public_id, '2026-11-15'));
        $this->assertFalse(app(IntegrationAccess::class)->usesCajas($second['operator']->public_id, '2026-11-15'));
        $this->assertSame($state, $this->financialState());
        Http::assertNothingSent();
    }

    private function operatorReadFixture(int $number): array
    {
        $actor = User::factory()->create();
        $operator = Operator::create([
            'public_id' => (string) Str::ulid(), 'name' => 'Operador ficticio '.$number, 'status' => 'active',
        ]);
        $client = Client::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $operator->id,
            'first_name' => 'Cliente', 'last_name' => 'Ficticio '.$number, 'phone' => '0000000000',
        ]);
        $loan = app(LoanFormalizer::class)->create($client, [
            'operator_id' => $operator->id, 'capital' => '12000', 'monthly_rate' => '0.02', 'term_months' => 6,
            'payment_day' => 12, 'start_date' => '2026-09-12', 'first_payment_date' => '2026-10-12', 'vat_enabled' => false,
        ]);
        $letter = $loan->installments()->where('number', 2)->firstOrFail();
        $paidLetter = $loan->installments()->where('number', 1)->firstOrFail();
        $investor = Investor::create([
            'public_id' => (string) Str::ulid(), 'name' => 'Inversionista ficticio '.$number, 'status' => 'active',
        ]);
        Investment::create([
            'public_id' => (string) Str::ulid(), 'investor_id' => $investor->id, 'loan_id' => $loan->id,
            'amount' => '12000.00', 'investor_share_rate' => '1.00', 'administrator_share_rate' => '0',
            'starts_on' => '2026-09-12', 'status' => 'active',
        ]);
        $payment = CollectionMovement::create([
            'public_id' => (string) Str::ulid(), 'folio' => 'NATIVE-READ-'.$number,
            'idempotency_key' => 'native-read-'.$number, 'loan_id' => $loan->id, 'operator_id' => $operator->id,
            'target_installment_id' => $paidLetter->id, 'registered_by' => $actor->id, 'operated_on' => '2026-11-15',
            'registered_at' => now(), 'contract_amount' => $paidLetter->remaining_amount,
            'type' => 'ordinary', 'payment_method' => 'cash', 'confirmation_status' => 'reported',
        ]);
        app(PaymentApplicationService::class)->confirm($payment, $actor->id);
        $credit = CajasV1PendingCredit::create([
            'public_id' => 'pending-read-'.$number, 'cut_id' => 'cut-read-'.$number, 'cut_version' => 1,
            'operator_id' => $operator->id, 'offset_id' => null, 'offset' => null,
            'proposal' => [], 'observations' => [], 'status' => 'pending',
        ]);
        DB::table('cajas_v1_cut_executions')->insert([
            'cut_id' => 'cut-read-'.$number, 'version' => 1, 'operator' => $operator->public_id,
            'status' => 'applied', 'body_hash' => hash('sha256', '{}'), 'body' => '{}',
            'result' => json_encode(['payments' => [], 'pending_credits' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('operator', 'letter', 'payment', 'credit');
    }

    private function assertOperatorReads(array $fixture, bool $forbidden = false): void
    {
        $operator = $fixture['operator']->public_id;
        $catalog = self::API.'/pagares?'.http_build_query([
            'operator' => $operator, 'cut_on' => '2026-11-15', 'installment_id' => $fixture['letter']->public_id,
        ]);
        $urls = [
            $catalog,
            self::API.'/cortes/'.$fixture['credit']->cut_id.'/versiones/1',
            self::API.'/creditos-pendientes/'.$fixture['credit']->public_id,
            self::API.'/pagos-directos/'.$fixture['payment']->public_id.'?currency=MXN',
        ];
        foreach ($urls as $url) {
            $reply = $this->withToken($this->readToken)->getJson($url);
            if ($forbidden) {
                $reply->assertForbidden()->assertJsonPath('error.code', 'OPERATOR_OUT_OF_SCOPE');
            } else {
                $reply->assertOk();
            }
        }
        if (! $forbidden) {
            $this->getJson($catalog)->assertJsonPath('data.0.installment_id', $fixture['letter']->public_id)
                ->assertJsonPath('data.0.allowed_payment_types', []);
            $this->getJson(self::API.'/pagares?'.http_build_query([
                'operator' => $operator, 'cut_on' => '2026-11-15',
            ]))->assertOk()->assertJsonPath('data', []);
        }
    }

    private function financialState(): array
    {
        $state = [];
        foreach ([
            'loans', 'installments', 'collection_movements', 'payment_allocations', 'investors', 'investments',
            'investor_capital_movements', 'investment_ledger_entries', 'operator_ledger_entries', 'fund_disbursements',
            'cajas_v1_cut_executions', 'cajas_v1_pending_credits', 'cajas_v1_payment_corrections', 'cajas_v1_funding_events',
            'cajas_v1_custody_movements', 'cajas_v1_custody_confirmations',
        ] as $table) {
            $state[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $state;
    }

    private function proof(string $pair): string
    {
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $claims = $encode(json_encode(['iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp], JSON_THROW_ON_ERROR));

        return $claims.'.'.$encode(sodium_crypto_sign_detached($claims, sodium_crypto_sign_secretkey($pair)));
    }
}
