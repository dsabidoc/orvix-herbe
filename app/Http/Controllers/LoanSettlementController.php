<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoanSettlementController extends Controller
{
    public function store(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('settlements.authorize') || $request->user()->hasRole('operador-cartera'), 403);

        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }

        $data = $request->validate([
            'settlement_reason' => ['required', 'in:pronto_pago_cliente,dejo_de_pagar'],
        ]);

        $loan->installments()->where('remaining_amount', '>', 0)->update([
            'status' => 'cancelled_by_settlement',
            'remaining_amount' => '0.00',
        ]);

        $loan->update([
            'status' => 'settled',
            'settlement_reason' => $data['settlement_reason'],
            'settled_at' => now('America/Merida'),
            'settled_by' => $request->user()->id,
        ]);

        return redirect()->route('loans.show', $loan)->with('status', 'Credito liquidado para Orvix.');
    }
}
