<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\LoanSettlementService;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(Request $request, Loan $loan, WeeklyCutPeriodService $cutPeriodService): RedirectResponse
    {
        $this->authorizeLoanAccess($request, $loan);

        $data = $request->validate([
            'type' => ['required', 'in:ordinary,partial,advance,settlement'],
            'operated_on' => ['required', 'date'],
            'contract_amount' => ['required', 'numeric', 'min:1'],
            'operator_surcharge_amount' => ['nullable', 'numeric', 'min:0'],
            'external_concepts_amount' => ['nullable', 'numeric', 'min:0'],
            'additional_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'delinquency_amount' => ['nullable', 'numeric', 'min:0'],
            'affects_investors' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'in:cash,transfer,card'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = sha1($loan->id.'|'.$request->user()->id.'|'.implode('|', [
            $data['type'],
            $data['operated_on'],
            Money::decimal(Money::cents($data['contract_amount'])),
            $data['reference'] ?? '',
        ]));

        $registeredAt = now(WeeklyCutPeriodService::TIMEZONE);
        $movement = CollectionMovement::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'public_id' => (string) Str::ulid(),
                'folio' => 'MOV-'.$registeredAt->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'loan_id' => $loan->id,
                'operator_id' => $loan->operator_id,
                'registered_by' => $request->user()->id,
                'operated_on' => $data['operated_on'],
                'registered_at' => $registeredAt,
                'contract_amount' => Money::decimal(Money::cents($data['contract_amount'])),
                'operator_surcharge_amount' => Money::decimal(Money::cents($data['operator_surcharge_amount'] ?? 0)),
                'external_concepts_amount' => Money::decimal(Money::cents($data['external_concepts_amount'] ?? 0)),
                'additional_charge_amount' => Money::decimal(Money::cents($data['additional_charge_amount'] ?? 0)),
                'delinquency_amount' => Money::decimal(Money::cents($data['delinquency_amount'] ?? 0)),
                'affects_investors' => (bool) ($data['affects_investors'] ?? true),
                'type' => $data['type'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'confirmation_status' => 'reported',
            ],
        );

        return redirect()
            ->route('loans.show', $loan)
            ->with($movement->wasRecentlyCreated ? 'status' : 'warning', $movement->wasRecentlyCreated ? 'Cobro registrado por confirmar. Se agregara al corte cuando se genere por fecha.' : 'Ese cobro ya estaba registrado; no se duplico.');
    }

    public function confirm(Request $request, CollectionMovement $movement, PaymentApplicationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('payments.confirm'), 403);

        try {
            $service->confirm($movement, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('status', 'Pago aplicado al calendario contractual.');
    }

    public function reverse(Request $request, CollectionMovement $movement, PaymentApplicationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('payments.confirm') && ! $request->user()->hasRole('operador-cartera'), 403);

        try {
            $service->reverse($movement, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('status', 'La letra regreso a pendiente y el boton Pagado queda disponible de nuevo.');
    }

    public function cancel(Request $request, CollectionMovement $movement, PaymentApplicationService $payments, LoanSettlementService $settlements): RedirectResponse
    {
        abort_unless($request->user()->can('payments.confirm') && ! $request->user()->hasRole('operador-cartera'), 403);

        if ($movement->confirmation_status !== 'reported') {
            return back()->with('warning', 'Solo se pueden cancelar movimientos por confirmar.');
        }

        try {
            if ($movement->type === 'settlement') {
                $settlements->cancelReportedSettlement($movement, $request->user()->id, 'Liquidacion reportada cancelada desde movimientos');
            } else {
                $payments->reverse($movement, $request->user()->id);
            }
        } catch (RuntimeException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return redirect()
            ->to(route('loans.show', $movement->loan).'#loan-movements')
            ->with('status', 'Movimiento cancelado; la letra o liquidacion regreso a su estado normal.');
    }

    private function authorizeLoanAccess(Request $request, Loan $loan): void
    {
        if ($request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage')) {
            abort(403);
        }

        if ($request->user()->hasRole('operador-cartera') && $loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }
}
