<?php

namespace App\Domain\Cajas\V1;

use App\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationAccess
{
    public function __construct(private PilotAccess $pilot) {}

    public function production(): bool
    {
        return app()->environment('production');
    }

    public function setting(string $name): mixed
    {
        return config(($this->production() ? 'cajas.v1_production.' : 'cajas.v1_pilot.').$name);
    }

    public function proofEnvironment(): string
    {
        return $this->production() ? 'production' : 'pilot';
    }

    public function enabled(): bool
    {
        if (! $this->production()) {
            return $this->pilot->enabled();
        }

        $database = $this->setting('database');
        if ($this->setting('enabled') !== true || config('app.debug') || ! $this->origin('public_url')
            || ! $this->origin('approval_url') || $this->origin('approval_url') === $this->origin('public_url')
            || ! is_string($database) || $database === '' || $database === 'prestamos_cajas_v1_pilot'
            || $database === config('cajas.v1_pilot.database')) {
            return false;
        }

        $connection = DB::connection();

        return $connection instanceof MySqlConnection && $connection->getDatabaseName() === $database;
    }

    public function availableTo(Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if (! $this->production()) {
            return in_array($request->ip(), ['127.0.0.1', '::1'], true);
        }

        $fromTrustedProxy = in_array($request->server('REMOTE_ADDR'), $this->setting('trusted_proxies'), true);
        $secure = $request->isSecure() || ($fromTrustedProxy && $request->header('X-Forwarded-Proto') === 'https');
        $host = strtolower((string) $request->header('Host'));
        $host = str_ends_with($host, ':443') ? substr($host, 0, -4) : $host;

        return $secure && 'https://'.$host === $this->origin('public_url');
    }

    public function authenticate(Request $request, string $permission): void
    {
        if (! $this->production()) {
            $this->pilot->authenticate($request);

            return;
        }
        if (! $this->availableTo($request)) {
            $this->fail(404, 'PILOT_UNAVAILABLE', 'La integración no está disponible.');
        }
        $presented = $request->bearerToken() ?? '';
        $current = $this->validToken($permission);
        $previous = $this->validToken($permission, previous: true);
        if ($current === null || (! hash_equals($current, $presented)
            && ($previous === null || ! hash_equals($previous, $presented)))) {
            $this->fail(401, 'INVALID_CREDENTIAL', 'La credencial del servicio no es válida para esta operación.');
        }
    }

    public function requireAdmission(): void
    {
        if ($this->production() && $this->setting('admissions') !== true) {
            $this->fail(503, 'INTEGRATION_PAUSED', 'La integración está pausada para operaciones nuevas.');
        }
    }

    public function operators(): array
    {
        if (! $this->production()) {
            return $this->setting('operators');
        }
        $operator = $this->setting('operator');

        return is_string($operator) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $operator) ? [$operator] : [];
    }

    public function authorizeOperator(string $operator, bool $readOnly = false): void
    {
        if ($readOnly && $this->production() && $this->setting('read_all_active_operators') === true) {
            if (! Operator::where('public_id', $operator)->where('status', 'active')->exists()) {
                $this->fail(403, 'OPERATOR_OUT_OF_SCOPE', 'El operador no está habilitado para este servicio.');
            }

            return;
        }

        if (! in_array($operator, $this->operators(), true)) {
            $this->fail(403, 'OPERATOR_OUT_OF_SCOPE', 'El operador no está habilitado para este servicio.');
        }
    }

    public function authorizeCustodyAccount(string $account): void
    {
        if (! in_array($account, $this->setting('custody_accounts'), true)) {
            $this->fail(403, 'CASH_ACCOUNT_OUT_OF_SCOPE', 'La caja no está habilitada para esta credencial.');
        }
    }

    public function usesCajas(?string $operator, string $date): bool
    {
        if (! $this->production()) {
            return $this->pilot->usesCajas($operator, $date);
        }
        $cutover = $this->setting('cutover_on');
        $effective = is_string($cutover) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $cutover) : false;

        return $effective && $effective->format('Y-m-d') === $cutover && in_array($operator, $this->operators(), true)
            && CarbonImmutable::parse($date, 'America/Merida')->toDateString() >= $cutover;
    }

    public function origin(string $setting): ?string
    {
        $url = $this->setting($setting);
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
    }

    public function rotationOpen(): bool
    {
        $value = $this->setting('rotation_until');
        if (! is_string($value)) {
            return false;
        }
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $now = now()->timestamp;

        return $until && $until->format('Y-m-d\TH:i:s\Z') === $value
            && $until->getTimestamp() > $now && $until->getTimestamp() <= $now + 86400;
    }

    public function validToken(string $permission, bool $previous = false): ?string
    {
        $current = [
            'approval' => $this->setting('approval_token'),
            'custody_read' => $this->setting('custody_read_token'),
            ...$this->setting('tokens'),
        ];
        $old = $this->rotationOpen() ? $this->setting('previous_tokens') : [];
        $token = ($previous ? $old : $current)[$permission] ?? null;
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9._~+\/=-]{32,}\z/', $token)
            || in_array($token, [config('cajas.capital_token'), config('cajas.v1_pilot.token'), config('cajas.v1_pilot.approval_token')], true)) {
            return null;
        }

        unset($current[$permission], $old[$permission]);

        return in_array($token, $current, true) || in_array($token, $old, true) ? null : $token;
    }

    public function fail(int $status, string $code, string $message, array $details = []): never
    {
        $this->pilot->fail($status, $code, $message, $details);
    }
}
