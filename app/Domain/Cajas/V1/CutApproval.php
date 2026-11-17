<?php

namespace App\Domain\Cajas\V1;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CutApproval
{
    public function __construct(private IntegrationAccess $access, private PilotSignature $signature) {}

    public function verify(array $cut, string $bodyHash, string $proof): void
    {
        $this->verifyOperation([
            'operation' => 'apply_cut',
            'cut_id' => $cut['cut_id'],
            'version' => (int) $cut['version'],
            'operator' => $cut['operator'],
            'reviewer_id' => $cut['approval']['approved_by'],
        ], $cut['approval'], $cut['prepared_by'], $bodyHash, $proof);
    }

    public function verifyCorrection(string $cutId, array $correction, string $bodyHash, string $proof): void
    {
        $this->verifyOperation([
            'operation' => 'correct_payment',
            'cut_id' => $cutId,
            'version' => $correction['cut_version'],
            'operator' => $correction['operator'],
            'reviewer_id' => $correction['approval']['approved_by'],
            'correction_id' => $correction['correction_id'],
        ], $correction['approval'], $correction['prepared_by'], $bodyHash, $proof);
    }

    private function verifyOperation(array $permission, array $approval, string $preparedBy, string $bodyHash, string $proof): void
    {
        $claims = $this->signature->claims($proof);
        if ($claims === null || $approval['approved_by'] === $preparedBy) {
            $this->invalid();
        }
        $expected = [
            ...$permission,
            'iss' => 'cajas',
            'aud' => 'prestamos',
            'environment' => $this->access->proofEnvironment(),
            'approval_id' => $approval['id'],
            'prepared_by' => $preparedBy,
            'body_sha256' => $bodyHash,
        ];
        foreach ($expected as $field => $value) {
            if (($claims[$field] ?? null) !== $value) {
                $this->invalid();
            }
        }

        if ($this->access->production()) {
            $base = $this->access->origin('approval_url');
            $token = $this->access->validToken('approval');
        } else {
            $pilotUrl = (string) $this->access->setting('approval_url');
            $base = preg_match('~\Ahttps?://127\.0\.0\.1(?::[0-9]{1,5})?/?\z~', $pilotUrl) ? $pilotUrl : null;
            $token = $this->access->setting('approval_token');
        }
        if ($base === null || ! is_string($token) || $token === '') {
            $this->unavailable();
        }

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->connectTimeout(2)->timeout(4)->post(
                    rtrim($base, '/').'/api/v1/cajas/autorizaciones/'.rawurlencode($approval['id']).'/verificar',
                    $permission,
                );
        } catch (ConnectionException) {
            $this->unavailable();
        }

        if ($response->status() === 200 && $response->json('allowed') === false) {
            $this->invalid();
        }
        if ($response->status() !== 200 || $response->json('allowed') !== true) {
            $this->unavailable();
        }
    }

    private function invalid(): never
    {
        $this->access->fail(403, 'APPROVAL_INVALID', 'La autorización no es válida o el revisor no tiene permiso.');
    }

    private function unavailable(): never
    {
        $this->access->fail(503, 'APPROVAL_CHECK_UNAVAILABLE', 'No fue posible verificar el permiso vigente en Cajas.');
    }
}
