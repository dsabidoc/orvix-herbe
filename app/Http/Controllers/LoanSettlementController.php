<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanSettlementService;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoanSettlementController extends Controller
{
    public function store(Request $request, Loan $loan, LoanSettlementService $service): RedirectResponse
    {
        abort_unless($request->user()->can('settlements.authorize') || $request->user()->hasRole('operador-cartera'), 403);

        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }

        $data = $request->validate([
            'settlement_reason' => ['required', 'in:pronto_pago_cliente,dejo_de_pagar'],
            'settled_on' => ['nullable', 'date'],
        ]);

        $service->settle(
            $loan,
            $data['settlement_reason'],
            $request->user()->id,
            CarbonImmutable::parse($data['settled_on'] ?? now('America/Merida')->toDateString(), 'America/Merida'),
        );

        return redirect()->route('loans.show', $loan)->with('status', 'Credito liquidado para Orvix.');
    }
}
