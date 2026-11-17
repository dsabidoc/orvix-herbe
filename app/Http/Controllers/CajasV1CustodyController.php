<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\V1\CustodyLedger;
use App\Domain\Cajas\V1\IntegrationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CajasV1CustodyController extends Controller
{
    public function __invoke(Request $request, string $close, IntegrationAccess $access, CustodyLedger $ledger): JsonResponse
    {
        $access->authenticate($request, 'read');
        $rules = Validator::make($request->query(), [
            'cash_account_id' => ['required', CustodyLedger::ID],
            'currency' => 'required|in:MXN',
            'scope' => 'required|in:'.CustodyLedger::SCOPE,
        ]);
        if ($rules->fails()) {
            $access->fail(422, 'INVALID_REQUEST', 'Indica la caja, moneda y alcance del cierre.', $rules->errors()->toArray());
        }
        $account = $rules->validated()['cash_account_id'];
        $access->authorizeCustodyAccount($account);
        $result = $ledger->result($account, $close);
        if ($result === null) {
            $access->fail(404, 'CUSTODY_CLOSE_NOT_FOUND', 'El cierre financiero todavía no está publicado para esta caja.');
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
