<?php

namespace App\Domain\Cajas\V1;

use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PilotAccess
{
    public function enabled(): bool
    {
        if (! config('cajas.v1_pilot.enabled') || ! app()->environment(['local', 'testing'])) {
            return false;
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $expected = config('cajas.v1_pilot.database');

        if ($connection instanceof MySqlConnection && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $expectedSocket = config('cajas.v1_pilot.mysql_socket');
            $socket = $connection->getConfig('unix_socket');
            $socketPath = is_string($socket) && str_starts_with($socket, '/') ? realpath($socket) : false;

            return $database === 'prestamos_cajas_v1_pilot' && $expected === $database
                && is_string($expectedSocket) && $socketPath !== false && $socketPath === realpath($expectedSocket)
                && filetype($socketPath) === 'socket';
        }

        if ($connection->getDriverName() !== 'sqlite') {
            return false;
        }

        return $database === ':memory:'
            ? app()->environment('testing')
            : is_string($expected) && realpath($expected) !== false && realpath($expected) === realpath($database)
                && basename($expected) === 'prestamos-cajas-v1-pilot.sqlite';
    }

    public function authenticate(Request $request): void
    {
        if (! $this->enabled() || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            $this->fail(404, 'PILOT_UNAVAILABLE', 'El piloto solo está habilitado en la copia aislada.');
        }
        $token = config('cajas.v1_pilot.token');
        if (! is_string($token) || $token === '' || ! hash_equals($token, $request->bearerToken() ?? '')) {
            $this->fail(401, 'INVALID_CREDENTIAL', 'La credencial del servicio no es válida.');
        }
    }

    public function authorizeOperator(string $operator): void
    {
        if (! in_array($operator, config('cajas.v1_pilot.operators', []), true)) {
            $this->fail(403, 'OPERATOR_OUT_OF_SCOPE', 'El operador no está habilitado para este servicio.');
        }
    }

    public function authorizeCustodyAccount(string $account): void
    {
        if (! in_array($account, config('cajas.v1_pilot.custody_accounts', []), true)) {
            $this->fail(403, 'CASH_ACCOUNT_OUT_OF_SCOPE', 'La caja no está habilitada para esta credencial.');
        }
    }

    public function usesCajas(?string $operator, string $date): bool
    {
        $cutover = config('cajas.v1_pilot.cutover_on');

        return $this->enabled() && is_string($cutover) && $cutover !== ''
            && in_array($operator, config('cajas.v1_pilot.operators', []), true)
            && CarbonImmutable::parse($date, 'America/Merida')->toDateString() >= $cutover;
    }

    public function fail(int $status, string $code, string $message, array $details = []): never
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => $code, 'message' => $message, ...($details ? ['details' => $details] : [])],
        ], $status));
    }
}
