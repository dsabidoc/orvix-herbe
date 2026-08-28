<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\LoanSettlementService;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Loan;
use App\Models\WeeklyCut;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LoanSettlementController extends Controller
{
    public function store(Request $request, Loan $loan, LoanSettlementService $service): RedirectResponse
    {
        abort_if($this->isInvestorReadOnly($request), 403);

        abort_unless($request->user()->can('settlements.authorize') || $request->user()->hasRole('operador-cartera'), 403);

        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }

        $data = $request->validate([
            'settlement_reason' => ['required', 'in:pronto_pago_cliente,dejo_de_pagar'],
            'settled_on' => ['nullable', 'date'],
            'return_to' => ['nullable', 'string', 'max:20'],
            'cut_id' => ['nullable', 'exists:weekly_cuts,id'],
        ]);

        $selectedCut = null;
        if (($data['return_to'] ?? null) === 'cut') {
            abort_unless($request->user()->can('weekly-cuts.confirm'), 403);
            abort_if(empty($data['cut_id']), 422, 'Selecciona el corte a ajustar.');
            $selectedCut = WeeklyCut::query()->findOrFail($data['cut_id']);
            abort_if($selectedCut->status === 'closed', 422, 'No se pueden registrar liquidaciones en un corte cerrado.');
            abort_if($selectedCut->operator_id !== $loan->operator_id, 422, 'La liquidacion no pertenece al operador de este corte.');
        }

        $service->settle(
            $loan,
            $data['settlement_reason'],
            $request->user()->id,
            CarbonImmutable::parse($data['settled_on'] ?? now('America/Merida')->toDateString(), 'America/Merida'),
            true,
        );

        $movement = CollectionMovement::query()
            ->where('loan_id', $loan->id)
            ->where('type', 'settlement')
            ->where('confirmation_status', 'reported')
            ->latest('id')
            ->first();

        if ($selectedCut) {
            if ($movement) {
                $movement->update(['origin_weekly_cut_id' => $selectedCut->id]);
                app(WeeklyCutPeriodService::class)->attachMovementToCut($movement, $selectedCut, $request->user()->id);
            }

            return redirect()->route('cuts.show', $selectedCut)->with('status', 'Credito liquidado y agregado a este corte.');
        }

        if ($movement) {
            app(WeeklyCutPeriodService::class)->attachMovementToOpenCutForOperatedDate($movement, $request->user()->id);
        }

        return redirect()->route('loans.show', $loan)->with('status', 'Liquidacion enviada al corte de la fecha seleccionada.');
    }

    public function reverse(Request $request, Loan $loan, PaymentApplicationService $service): RedirectResponse
    {
        abort_if($this->isInvestorReadOnly($request), 403);

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

    private function isInvestorReadOnly(Request $request): bool
    {
        return $request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage');
    }
}
