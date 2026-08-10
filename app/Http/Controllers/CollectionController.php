<?php

namespace App\Http\Controllers;

use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Operator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        $selectedMonth = CarbonImmutable::parse($request->input('month', now('America/Merida')->format('Y-m').'-01'));
        $monthStart = $selectedMonth->startOfMonth();
        $monthEnd = $selectedMonth->endOfMonth();
        $operatorId = $this->selectedOperatorId($request);
        $loanScope = function ($query) use ($request, $operatorId) {
            $query->where('status', 'active');

            if ($request->user()->hasRole('operador-cartera')) {
                $query->where('operator_id', $request->user()->operatorProfile?->id);
            } elseif ($operatorId) {
                $query->where('operator_id', $operatorId);
            }
        };

        $installments = Installment::query()
            ->with(['loan.client', 'loan.operator', 'loan.vehicle', 'reportedMovement'])
            ->whereHas('loan', $loanScope)
            ->where(function ($query) use ($monthStart, $monthEnd) {
                $query
                    ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhere(function ($query) use ($monthStart) {
                        $query->whereDate('due_date', '<', $monthStart->toDateString())
                            ->where('remaining_amount', '>', 0);
                    });
            })
            ->orderBy('due_date')
            ->paginate(60)
            ->withQueryString();

        $monthInstallments = Installment::query()
            ->whereHas('loan', $loanScope)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
        $reportedPendingCents = CollectionMovement::query()
            ->whereHas('loan', $loanScope)
            ->where('confirmation_status', 'reported')
            ->whereBetween('operated_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('contract_amount') * 100;
        $overdueCents = Installment::query()
            ->whereHas('loan', $loanScope)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereDate('due_date', '<', now('America/Merida')->toDateString())
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;
        $weekStart = now('America/Merida')->startOfWeek()->toDateString();
        $weekEnd = now('America/Merida')->endOfWeek()->toDateString();
        $weekStart = max($weekStart, $monthStart->toDateString());
        $weekEnd = min($weekEnd, $monthEnd->toDateString());
        $expectedWeekCents = Installment::query()
            ->whereHas('loan', $loanScope)
            ->whereBetween('due_date', [$weekStart, $weekEnd])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;

        return view('collections.index', [
            'installments' => $installments,
            'kpis' => [
                ['title' => 'Cartera del mes', 'value' => Money::mxn(Money::decimal((int) ((clone $monthInstallments)->sum('remaining_amount') * 100))), 'caption' => 'Saldo de letras del mes', 'color' => 'blue'],
                ['title' => 'Esperado semanal', 'value' => Money::mxn(Money::decimal((int) $expectedWeekCents)), 'caption' => 'Letras del mes en esta semana', 'color' => 'orange'],
                ['title' => 'Esperado del mes', 'value' => Money::mxn(Money::decimal((int) ((clone $monthInstallments)->sum('contract_amount') * 100))), 'caption' => 'Calendario mensual', 'color' => 'yellow'],
                ['title' => 'Reportado pendiente', 'value' => Money::mxn(Money::decimal((int) $reportedPendingCents)), 'caption' => 'Cobros aun por confirmar', 'color' => 'green'],
                ['title' => 'Vencido', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => 'Letras vencidas del mes', 'color' => 'red'],
            ],
            'month' => $selectedMonth,
            'operators' => $request->user()->hasRole('operador-cartera')
                ? collect([$request->user()->operatorProfile])->filter()
                : Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'selectedOperatorId' => $operatorId,
        ]);
    }

    public function markPaid(Request $request, Installment $installment): RedirectResponse
    {
        $installment->load('loan.operator');
        $this->authorizeInstallmentAccess($request, $installment);

        if (Money::cents($installment->remaining_amount) <= 0) {
            return back()->with('warning', 'Esta letra ya esta cubierta.');
        }

        $existing = CollectionMovement::query()
            ->where('target_installment_id', $installment->id)
            ->whereIn('confirmation_status', ['reported', 'applied'])
            ->first();

        if ($existing) {
            return back()->with('warning', 'Esta letra ya esta marcada como pagada o por confirmar; no se duplico.');
        }

        $data = $request->validate([
            'operated_on' => ['required', 'date'],
            'contract_amount' => ['required', 'numeric', 'min:1'],
            'operator_surcharge_amount' => ['nullable', 'numeric', 'min:0'],
            'external_concepts_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:20'],
        ]);

        CollectionMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'MOV-'.now('America/Merida')->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'idempotency_key' => sha1('installment|'.$installment->id.'|'.$data['operated_on'].'|'.Money::decimal(Money::cents($data['contract_amount']))),
            'loan_id' => $installment->loan_id,
            'target_installment_id' => $installment->id,
            'operator_id' => $installment->loan->operator_id,
            'registered_by' => $request->user()->id,
            'operated_on' => $data['operated_on'],
            'contract_amount' => Money::decimal(Money::cents($data['contract_amount'])),
            'operator_surcharge_amount' => Money::decimal(Money::cents($data['operator_surcharge_amount'] ?? 0)),
            'external_concepts_amount' => Money::decimal(Money::cents($data['external_concepts_amount'] ?? 0)),
            'type' => 'ordinary',
            'payment_method' => 'cash',
            'notes' => $data['notes'] ?? 'Marcado pagado desde cobranza',
            'confirmation_status' => 'reported',
        ]);

        $route = match ($data['return_to'] ?? '') {
            'loan' => route('loans.show', $installment->loan),
            'dashboard' => route('dashboard'),
            default => route('collections.index', ['month' => $installment->due_date->format('Y-m'), 'operator_id' => $installment->loan->operator_id]),
        };

        return redirect($route)->with('status', 'Letra marcada como pagada; aparecera en el corte semanal.');
    }

    private function selectedOperatorId(Request $request): ?int
    {
        if ($request->user()->hasRole('operador-cartera')) {
            return $request->user()->operatorProfile?->id;
        }

        return $request->filled('operator_id') ? (int) $request->input('operator_id') : null;
    }

    private function authorizeInstallmentAccess(Request $request, Installment $installment): void
    {
        if ($request->user()->hasRole('operador-cartera') && $installment->loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }
}
