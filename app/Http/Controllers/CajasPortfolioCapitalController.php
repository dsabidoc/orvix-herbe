<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\V1\IntegrationAccess;
use App\Models\Investor;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajasPortfolioCapitalController extends Controller
{
    public function __invoke(Request $request, IntegrationAccess $access): JsonResponse
    {
        $expected = config('cajas.capital_token');
        $presented = $request->bearerToken();
        if ($access->production()) {
            $access->authenticate($request, 'capital');
        } elseif (! (is_string($expected) && filled($expected) && is_string($presented) && hash_equals($expected, $presented))) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIAL',
                    'message' => 'La credencial del servicio no es válida.',
                ],
            ], 401);
        }

        $request->validate([
            'scope' => ['required', 'in:portfolio'],
            'currency' => ['required', 'in:MXN'],
        ]);

        $available = Investor::availableForFunding()->pluck('available_capital')
            ->sum(fn ($capital): int => Money::cents($capital));

        return response()->json([
            'scope' => 'portfolio',
            'currency' => 'MXN',
            'available_principal_cents' => $available,
            'as_of' => now()->utc()->toISOString(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
