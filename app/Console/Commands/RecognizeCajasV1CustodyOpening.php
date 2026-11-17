<?php

namespace App\Console\Commands;

use App\Domain\Cajas\V1\CustodyLedger;
use App\Domain\Cajas\V1\IntegrationAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class RecognizeCajasV1CustodyOpening extends Command
{
    protected $signature = 'cajas:v1-recognize-opening {file : Acta JSON con el importe respaldado} {--actor= : Correo del usuario financiero que autoriza}';

    protected $description = 'Reconoce la apertura de custodia confirmada en Cajas';

    public function handle(IntegrationAccess $access, CustodyLedger $ledger): int
    {
        if (! $this->input->isInteractive() || ! $access->production() || ! $access->enabled() || $access->setting('admissions') !== false) {
            $this->error('Se requiere una sesión interactiva y el perfil productivo habilitado con admisiones pausadas.');

            return self::FAILURE;
        }

        try {
            $actor = User::where('email', $this->option('actor'))->first();
            if (! $actor || $actor->status !== 'active' || $actor->locked_at || $actor->force_password_change
                || ! $actor->can('investors.manage') || ! Hash::check((string) $this->secret('Contraseña del usuario financiero', false), $actor->password)) {
                throw new RuntimeException('El usuario financiero no está autorizado para reconocer la apertura.');
            }

            $opening = $this->readOpening($this->argument('file'));
            $access->authorizeCustodyAccount($opening['cash_account_id']);
            $counted = $this->verifiedCount($access, $opening);
            if ($opening['amount_cents'] > $counted) {
                throw new RuntimeException('El importe respaldado supera el efectivo confirmado por Cajas.');
            }
            if (! $this->confirm(sprintf('¿Reconocer %d de %d centavos MXN en el arqueo %s?',
                $opening['amount_cents'], $counted, $opening['opening_close_id']))) {
                return self::FAILURE;
            }

            $currentActor = $actor->fresh();
            if (! $currentActor || $currentActor->force_password_change || ! hash_equals($actor->password, $currentActor->password)) {
                throw new RuntimeException('Cambió la autorización del usuario. Inicia de nuevo el reconocimiento.');
            }
            $result = $ledger->recognizeOpening($opening, $counted, $currentActor);
        } catch (ConnectionException) {
            $this->error('No se pudo verificar el arqueo por HTTPS con Cajas.');

            return self::FAILURE;
        } catch (HttpResponseException|JsonException|RuntimeException|ValidationException $exception) {
            $this->error($exception instanceof HttpResponseException
                ? 'La caja está fuera del alcance autorizado.' : $exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function readOpening(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('El acta de apertura no está disponible.');
        }

        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $opening = Validator::make(['opening' => $data], [
            'opening' => 'required|array:cash_account_id,opening_close_id,currency,cutoff_at,amount_cents,evidence_ref',
            'opening.cash_account_id' => 'required|ulid',
            'opening.opening_close_id' => 'required|ulid',
            'opening.currency' => 'required|in:MXN',
            'opening.cutoff_at' => ['required', 'date', 'regex:/T.+Z\z/'],
            'opening.amount_cents' => 'required|integer|between:0,999999999999999',
            'opening.evidence_ref' => 'required|string|max:255',
        ])->validate()['opening'];

        $opening['amount_cents'] = (int) $opening['amount_cents'];
        $opening['cutoff_at'] = CarbonImmutable::parse($opening['cutoff_at'])->utc()->toISOString();
        unset($opening['currency']);

        return $opening;
    }

    private function verifiedCount(IntegrationAccess $access, array $opening): int
    {
        $token = $access->validToken('custody_read');
        $origin = $access->origin('approval_url');
        if ($token === null || $origin === null) {
            throw new RuntimeException('Falta la credencial de lectura de custodia de Cajas.');
        }

        $account = $opening['cash_account_id'];
        $folio = $opening['opening_close_id'];
        $response = Http::acceptJson()->withToken($token)->withoutRedirecting()->connectTimeout(2)->timeout(5)
            ->get($origin.'/api/v1/cajas/cuentas/'.$account.'/saldo', [
                'currency' => 'MXN', 'scope' => CustodyLedger::SCOPE, 'close_id' => $folio,
            ]);
        $counted = $response->json('available_cents');
        $supported = $response->json('opening_cents');
        $coverageVerified = match ($response->json('coverage_status')) {
            'complete' => $supported === $counted && $supported === $opening['amount_cents'],
            'pending' => $response->json('pending_reasons') === ['OPENING_ATTRIBUTION_PENDING']
                && $supported === $opening['amount_cents'] && $supported < $counted
                && $response->json('in_scope_cents') === $supported,
            default => false,
        };
        if (! $response->ok() || ! is_int($counted) || $counted < 0 || $counted > 999999999999999
            || $response->json('cash_account_id') !== $account || $response->json('currency') !== 'MXN'
            || $response->json('scope') !== CustodyLedger::SCOPE || $response->json('close_id') !== $folio
            || $response->json('opening_close_id') !== $folio || $response->json('cutoff_at') !== $opening['cutoff_at']
            || ! $coverageVerified) {
            throw new RuntimeException('El folio, corte o efectivo de apertura no coincide con el arqueo confirmado en Cajas.');
        }

        return $counted;
    }
}
