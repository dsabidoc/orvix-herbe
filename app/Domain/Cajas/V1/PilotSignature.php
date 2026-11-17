<?php

namespace App\Domain\Cajas\V1;

class PilotSignature
{
    public function __construct(private IntegrationAccess $access) {}

    public function claims(string $proof): ?array
    {
        [$encodedClaims, $encodedSignature] = array_pad(explode('.', $proof, 2), 2, '');
        $signature = $this->decode($encodedSignature);
        if (! function_exists('sodium_crypto_sign_verify_detached') || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! $this->verify($signature, $encodedClaims)) {
            return null;
        }
        $claims = json_decode($this->decode($encodedClaims), true);
        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= now()->timestamp
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > now()->timestamp + 30
            || $claims['iat'] >= $claims['exp']) {
            return null;
        }

        return $claims;
    }

    private function verify(string $signature, string $encodedClaims): bool
    {
        $current = $this->decodeKey($this->access->setting('approval_public_key'));
        $pilot = $this->access->production() ? $this->decodeKey(config('cajas.v1_pilot.approval_public_key')) : null;
        if ($current === null || $current === $pilot) {
            return false;
        }
        if (sodium_crypto_sign_verify_detached($signature, $encodedClaims, $current)) {
            return true;
        }
        if (! $this->access->production() || ! $this->access->rotationOpen()) {
            return false;
        }
        $previous = $this->decodeKey($this->access->setting('previous_approval_public_key'));

        return $previous !== null && $previous !== $pilot
            && sodium_crypto_sign_verify_detached($signature, $encodedClaims, $previous);
    }

    private function decodeKey(mixed $encodedKey): ?string
    {
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    private function decode(string $part): string
    {
        return base64_decode(strtr($part, '-_', '+/'), true) ?: '';
    }
}
