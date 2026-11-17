<?php

namespace Tests\Feature;

use App\Domain\Cajas\V1\IntegrationAccess;
use App\Domain\Loans\LoanFormalizer;
use App\Models\Client;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CajasV1AssignedOperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://prestamos.example.test/api/v1/cajas';

    private string $readToken;

    private string $writeToken;

    private string $signingPair;

    protected function setUp(): void
    {
        parent::setUp();
        if (! (DB::connection() instanceof MySqlConnection)) {
            $this->markTestSkipped('El perfil productivo requiere una base MariaDB aislada.');
        }
        $this->travelTo(now('UTC')->setDate(2026, 11, 17)->setTime(18, 0));
        $this->app->instance('env', 'production');
        $this->readToken = bin2hex(random_bytes(32));
        $this->writeToken = bin2hex(random_bytes(32));
        $this->signingPair = sodium_crypto_sign_keypair();
        $actor = User::factory()->create();
        config([
            'app.debug' => false,
            'cajas.v1_production.enabled' => true,
            'cajas.v1_production.admissions' => false,
            'cajas.v1_production.database' => DB::connection()->getDatabaseName(),
            'cajas.v1_production.public_url' => 'https://prestamos.example.test',
            'cajas.v1_production.approval_url' => 'https://cajas.example.test',
            'cajas.v1_production.trusted_proxies' => [],
            'cajas.v1_production.tokens.read' => $this->readToken,
            'cajas.v1_production.tokens.write' => $this->writeToken,
            'cajas.v1_production.approval_token' => bin2hex(random_bytes(32)),
            'cajas.v1_production.approval_public_key' => base64_encode(sodium_crypto_sign_publickey($this->signingPair)),
            'cajas.v1_production.audit_user_email' => $actor->email,
            'cajas.v1_production.read_all_active_operators' => true,
            'cajas.v1_production.write_operators' => [],
            'cajas.v1_production.cutover_on' => '2026-11-17',
            'cajas.v1_production.custody_accounts' => ['cash-fixture'],
        ]);
        Http::preventStrayRequests();
    }

    public function test_write_allowlist_excludes_unassigned_and_inactive_operators_and_supports_revocation(): void
    {
        $first = $this->fixture(1);
        $second = $this->fixture(2);
        $unassigned = $this->fixture(3);
        $inactive = $this->fixture(4);
        $inactive['operator']->update(['status' => 'inactive']);
        config([
            'cajas.v1_production.operator' => $first['operator']->public_id,
            'cajas.v1_production.write_operators' => [
                $first['operator']->public_id, $second['operator']->public_id, $inactive['operator']->public_id,
            ],
        ]);
        $access = app(IntegrationAccess::class);
        $before = $this->financialState();
        $this->assertSame([$first['operator']->public_id, $second['operator']->public_id], $access->operators());
        foreach ([$first, $second] as $fixture) {
            $this->assertFalse($access->usesCajas($fixture['operator']->public_id, '2026-11-16'));
            $this->assertTrue($access->usesCajas($fixture['operator']->public_id, '2026-11-17'));
            $this->submit($this->cut($fixture))->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_PAUSED');
        }
        foreach ([$unassigned, $inactive] as $fixture) {
            $this->assertFalse($access->usesCajas($fixture['operator']->public_id, '2026-11-17'));
            $identity = [
                'cut_id' => 'excluded-'.$fixture['operator']->id, 'version' => 1, 'operator' => $fixture['operator']->public_id,
            ];
            $this->withToken($this->writeToken)->withHeader('Idempotency-Key', 'cut:'.$identity['cut_id'].':v1')
                ->postJson(self::API.'/cortes', $identity)->assertForbidden()->assertJsonPath('error.code', 'OPERATOR_OUT_OF_SCOPE');
        }
        $this->catalog($unassigned)->assertOk()->assertJsonPath('data.0.allowed_payment_types', []);
        config(['cajas.v1_production.write_operators' => [$first['operator']->public_id]]);
        $this->assertFalse($access->usesCajas($second['operator']->public_id, '2026-11-17'));
        config(['cajas.v1_production.write_operators' => []]);
        $this->assertSame([], $access->operators());
        $this->assertFalse($access->usesCajas($first['operator']->public_id, '2026-11-17'));
        config(['cajas.v1_production.write_operators' => [$first['operator']->public_id, 'invalid,scope']]);
        $this->assertSame([], $access->operators());
        config(['cajas.v1_production.write_operators' => [$first['operator']->public_id]]);
        $first['operator']->update(['status' => 'inactive']);
        $this->assertSame([], $access->operators());
        $first['operator']->update(['status' => 'active']);
        config(['cajas.v1_production.write_operators' => null]);
        $this->assertSame([$first['operator']->public_id], $access->operators());
        $this->assertSame($before, $this->financialState());
        Http::assertNothingSent();
    }

    public function test_two_operators_need_signed_current_approval_and_retries_preserve_one_application(): void
    {
        $first = $this->fixture(1);
        $second = $this->fixture(2);
        config([
            'cajas.v1_production.admissions' => true,
            'cajas.v1_production.write_operators' => [$first['operator']->public_id, $second['operator']->public_id],
        ]);
        $allowed = false;
        Http::fake(function () use (&$allowed) {
            return Http::response(['allowed' => $allowed]);
        });
        $cuts = [$this->cut($first), $this->cut($second)];
        $before = $this->financialState();
        $this->submit($cuts[0], wrongSignature: true)->assertForbidden()->assertJsonPath('error.code', 'APPROVAL_INVALID');
        Http::assertNothingSent();
        $this->submit($cuts[0])->assertForbidden()->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->assertSame($before, $this->financialState());
        $allowed = true;
        foreach ($cuts as $cut) {
            $this->submit($cut)->assertAccepted();
        }
        $this->artisan('cajas:v1-process-cuts')->assertSuccessful();
        foreach ($cuts as $cut) {
            $this->withToken($this->readToken)->getJson(self::API.'/cortes/'.$cut['cut_id'].'/versiones/1')
                ->assertOk()->assertJsonPath('status', 'applied')->assertJsonCount(1, 'payments');
        }
        $this->assertDatabaseCount('collection_movements', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('investor_capital_movements', 2);
        $this->assertDatabaseCount('operator_ledger_entries', 4);
        $applied = $this->financialState();
        $allowed = false;
        foreach ($cuts as $cut) {
            $this->submit($cut)->assertAccepted();
            $this->submit($cut)->assertAccepted();
        }
        $this->artisan('cajas:v1-process-cuts')->assertSuccessful();
        Http::assertSentCount(3);
        $this->submit([...$cuts[0], 'cut_id' => 'new-cut-after-permission-revoked'])
            ->assertForbidden()->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->assertSame($applied, $this->financialState());
    }

    private function fixture(int $number): array
    {
        $operator = Operator::create(['public_id' => (string) Str::ulid(), 'name' => 'Operador ficticio '.$number, 'status' => 'active']);
        $client = Client::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $operator->id,
            'first_name' => 'Cliente', 'last_name' => 'Ficticio '.$number, 'phone' => '0000000000',
        ]);
        $loan = app(LoanFormalizer::class)->create($client, [
            'operator_id' => $operator->id, 'capital' => '12000', 'monthly_rate' => '0.02', 'term_months' => 6,
            'payment_day' => 12, 'start_date' => '2026-09-12', 'first_payment_date' => '2026-10-12', 'vat_enabled' => false,
        ]);
        $letter = $loan->installments()->where('number', 1)->firstOrFail();
        $investor = Investor::create(['public_id' => (string) Str::ulid(), 'name' => 'Inversionista ficticio '.$number, 'status' => 'active']);
        Investment::create([
            'public_id' => (string) Str::ulid(), 'investor_id' => $investor->id, 'loan_id' => $loan->id,
            'amount' => '12000.00', 'investor_share_rate' => '1.00', 'administrator_share_rate' => '0',
            'starts_on' => '2026-09-12', 'status' => 'active',
        ]);

        return compact('operator', 'letter');
    }

    private function catalog(array $fixture): TestResponse
    {
        return $this->withToken($this->readToken)->getJson(self::API.'/pagares?'.http_build_query([
            'operator' => $fixture['operator']->public_id, 'installment_id' => $fixture['letter']->public_id, 'cut_on' => '2026-11-17',
        ]));
    }

    private function cut(array $fixture): array
    {
        $row = $this->catalog($fixture)->assertOk()->json('data.0');
        $amount = $row['remaining_cents'];
        $id = $fixture['operator']->id;

        return [
            'cut_id' => 'assigned-cut-'.$id, 'version' => 1, 'operator' => $fixture['operator']->public_id,
            'cut_on' => '2026-11-17', 'currency' => 'MXN', 'prepared_by' => 'adriana-fixture',
            'approval' => ['id' => 'assigned-approval-'.$id, 'approved_by' => 'herbe-fixture', 'approved_at' => now()->utc()->toISOString()],
            'items' => [[
                'item_id' => 'assigned-item-'.$id, 'installment_id' => $row['installment_id'], 'installment_version' => $row['version'],
                'payment_type' => 'ordinary', 'contract_amount_cents' => $amount, 'reported_amount_cents' => $amount, 'extras' => [],
            ]],
            'totals' => ['reported_cents' => $amount, 'received_cents' => $amount, 'offset_cents' => 0, 'paid_cents' => 0, 'remaining_cents' => 0],
            'receipts' => [['receipt_id' => 'assigned-receipt-'.$id, 'cash_account_id' => 'cash-fixture', 'amount_cents' => $amount, 'received_on' => '2026-11-17']],
            'offsets' => [], 'payouts' => [], 'disbursements' => [], 'pending_credits' => [],
        ];
    }

    private function submit(array $cut, bool $wrongSignature = false): TestResponse
    {
        $body = json_encode($cut, JSON_THROW_ON_ERROR);
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $claims = $encode(json_encode([
            'iss' => 'cajas', 'aud' => 'prestamos', 'environment' => 'production', 'operation' => 'apply_cut',
            'cut_id' => $cut['cut_id'], 'version' => $cut['version'], 'operator' => $cut['operator'],
            'approval_id' => $cut['approval']['id'], 'reviewer_id' => $cut['approval']['approved_by'],
            'prepared_by' => $cut['prepared_by'], 'iat' => now()->timestamp - 2, 'exp' => now()->timestamp + 300,
            'body_sha256' => hash('sha256', $body),
        ], JSON_THROW_ON_ERROR));
        $pair = $wrongSignature ? sodium_crypto_sign_keypair() : $this->signingPair;
        $proof = $claims.'.'.$encode(sodium_crypto_sign_detached($claims, sodium_crypto_sign_secretkey($pair)));

        return $this->call('POST', self::API.'/cortes', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writeToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'cut:'.$cut['cut_id'].':v'.$cut['version'], 'HTTP_APPROVAL_PROOF' => $proof,
        ], content: $body);
    }

    private function financialState(): array
    {
        $state = [];
        foreach ([
            'loans', 'installments', 'collection_movements', 'payment_allocations', 'investors', 'investments',
            'investor_capital_movements', 'investment_ledger_entries', 'operator_ledger_entries', 'fund_disbursements',
            'cajas_v1_cut_executions', 'cajas_v1_pending_credits',
        ] as $table) {
            $state[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $state;
    }
}
