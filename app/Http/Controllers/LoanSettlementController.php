<?php

namespace App\Http\Controllers;

use App\Domain\Loans\LoanSettlementService;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

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

    public function reverse(Request $request, Loan $loan, PaymentApplicationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('settlements.authorize') && ! $request->user()->hasRole('operador-cartera'), 403);

        $movement = CollectionMovement::query()
            ->where('loan_id', $loan->id)
            ->where('type', 'settlement')
            ->where('confirmation_status', 'applied')
            ->latest('confirmed_at')
            ->latest('id')
            ->first();

        if (! $movement) {
            return back()->with('warning', 'Este prestamo no tiene una liquidacion activa para cancelar.');
        }

        try {
            $service->reverseSettlement($movement, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return redirect()->route('loans.show', $loan)->with('status', 'Liquidacion cancelada; el prestamo regreso a activo.');
    }
}
