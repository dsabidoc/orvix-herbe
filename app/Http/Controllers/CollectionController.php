<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Operator;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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
            $query->where('status', 'active')->where('is_frozen', false);

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
        $monthOperationalCents = (clone $monthInstallments)
            ->selectRaw('COALESCE(SUM(principal_amount + interest_amount), 0) as subtotal')
            ->value('subtotal') * 100;
        $reportedPendingCents = CollectionMovement::query()
            ->whereHas('loan', $loanScope)
            ->where('confirmation_status', 'reported')
            ->whereBetween(DB::raw('COALESCE(registered_at, created_at)'), [$monthStart->startOfDay(), $monthEnd->endOfDay()])
            ->sum('contract_amount') * 100;
        $overdueCents = Installment::query()
            ->whereHas('loan', $loanScope)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereDate('due_date', '<', now('America/Merida')->toDateString())
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;
        $cutPeriod = app(WeeklyCutPeriodService::class)->periodFor(now('America/Merida'));
        $weekStart = $cutPeriod['start']->toDateString();
        $weekEnd = $cutPeriod['end']->toDateString();
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
                ['title' => 'Esperado del mes', 'value' => Money::mxn(Money::decimal((int) $monthOperationalCents)), 'caption' => 'Calendario mensual', 'color' => 'yellow'],
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

    public function markPaid(Request $request, Installment $installment, WeeklyCutPeriodService $cutPeriodService, PaymentApplicationService $paymentApplicationService): RedirectResponse
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
            'additional_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'delinquency_amount' => ['nullable', 'numeric', 'min:0'],
            'affects_investors' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:20'],
            'cut_id' => ['nullable', 'exists:weekly_cuts,id'],
        ]);

        $selectedCut = null;
        if (($data['return_to'] ?? null) === 'cut') {
            abort_unless($request->user()->can('weekly-cuts.confirm'), 403);
            abort_if(empty($data['cut_id']), 422, 'Selecciona el corte a ajustar.');
            $selectedCut = WeeklyCut::query()->findOrFail($data['cut_id']);
            abort_if($selectedCut->status === 'closed', 422, 'No se pueden registrar cobros en un corte cerrado.');
            abort_if($selectedCut->operator_id !== $installment->loan->operator_id, 422, 'El cobro no pertenece al operador de este corte.');
        }

        $registeredAt = now(WeeklyCutPeriodService::TIMEZONE);
        $movement = CollectionMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'folio' => 'MOV-'.$registeredAt->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'idempotency_key' => sha1('installment|'.$installment->id.'|'.$data['operated_on'].'|'.Money::decimal(Money::cents($data['contract_amount']))),
            'loan_id' => $installment->loan_id,
            'target_installment_id' => $installment->id,
            'operator_id' => $installment->loan->operator_id,
            'registered_by' => $request->user()->id,
            'operated_on' => $data['operated_on'],
            'registered_at' => $registeredAt,
            'contract_amount' => Money::decimal(Money::cents($data['contract_amount'])),
            'operator_surcharge_amount' => Money::decimal(Money::cents($data['operator_surcharge_amount'] ?? 0)),
            'external_concepts_amount' => Money::decimal(Money::cents($data['external_concepts_amount'] ?? 0)),
            'additional_charge_amount' => Money::decimal(Money::cents($data['additional_charge_amount'] ?? 0)),
            'delinquency_amount' => Money::decimal(Money::cents($data['delinquency_amount'] ?? 0)),
            'affects_investors' => (bool) ($data['affects_investors'] ?? true),
            'origin_weekly_cut_id' => $selectedCut?->id,
            'type' => 'ordinary',
            'payment_method' => 'cash',
            'notes' => $data['notes'] ?? 'Marcado pagado desde cobranza',
            'confirmation_status' => 'reported',
        ]);

        if (($data['return_to'] ?? null) === 'cut') {
            $cutPeriodService->attachMovementToCut(
                $movement,
                $selectedCut,
                $request->user()->id,
            );
        }

        if ($this->shouldApplyImmediately($request)) {
            try {
                $paymentApplicationService->confirm($movement, $request->user()->id);
            } catch (RuntimeException $exception) {
                return back()->with('warning', $exception->getMessage());
            }
        }

        $route = match ($data['return_to'] ?? '') {
            'loan' => route('loans.show', $installment->loan),
            'dashboard' => route('dashboard'),
            'cut' => route('cuts.show', WeeklyCut::query()->findOrFail($data['cut_id'])),
            default => route('collections.index', ['month' => $installment->due_date->format('Y-m'), 'operator_id' => $installment->loan->operator_id]),
        };

        return redirect($route)->with('status', $this->shouldApplyImmediately($request) ? 'Letra marcada como pagada y aplicada al calendario.' : (($data['return_to'] ?? null) === 'cut' ? 'Letra marcada como pagada y agregada a este corte.' : 'Letra marcada como pagada; aparecera cuando se genere el corte de esa fecha.'));
    }

    public function markPaidBulk(Request $request, WeeklyCutPeriodService $cutPeriodService, PaymentApplicationService $paymentApplicationService): RedirectResponse
    {
        $data = $request->validate([
            'installment_ids' => ['required', 'array', 'min:1', 'max:80'],
            'installment_ids.*' => ['integer', 'exists:installments,id'],
            'loan_id' => ['required', 'exists:loans,id'],
            'operated_on' => ['required', 'date'],
            'affects_investors' => ['nullable', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:20'],
        ]);

        $installments = Installment::query()
            ->with('loan.operator')
            ->whereIn('id', $data['installment_ids'])
            ->where('loan_id', $data['loan_id'])
            ->orderBy('number')
            ->get();

        abort_if($installments->isEmpty(), 422, 'Selecciona al menos una letra valida.');

        $created = 0;
        foreach ($installments as $installment) {
            $this->authorizeInstallmentAccess($request, $installment);

            if (Money::cents($installment->remaining_amount) <= 0) {
                continue;
            }

            $existing = CollectionMovement::query()
                ->where('target_installment_id', $installment->id)
                ->whereIn('confirmation_status', ['reported', 'applied'])
                ->exists();

            if ($existing) {
                continue;
            }

            $registeredAt = now(WeeklyCutPeriodService::TIMEZONE);
            $movement = CollectionMovement::query()->create([
                'public_id' => (string) Str::ulid(),
                'folio' => 'MOV-'.$registeredAt->format('ymd').'-'.str_pad((string) (CollectionMovement::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'idempotency_key' => sha1('bulk-installment|'.$installment->id.'|'.$data['operated_on'].'|'.Money::decimal(Money::cents($installment->remaining_amount))),
                'loan_id' => $installment->loan_id,
                'target_installment_id' => $installment->id,
                'operator_id' => $installment->loan->operator_id,
                'registered_by' => $request->user()->id,
                'operated_on' => $data['operated_on'],
                'registered_at' => $registeredAt,
                'contract_amount' => Money::decimal(Money::cents($installment->remaining_amount)),
                'operator_surcharge_amount' => '0.00',
                'external_concepts_amount' => '0.00',
                'additional_charge_amount' => '0.00',
                'delinquency_amount' => '0.00',
                'affects_investors' => (bool) ($data['affects_investors'] ?? true),
                'type' => 'ordinary',
                'payment_method' => 'cash',
                'notes' => 'Marcado pagado en bloque desde calendario contractual',
                'confirmation_status' => 'reported',
            ]);

            if ($this->shouldApplyImmediately($request)) {
                try {
                    $paymentApplicationService->confirm($movement, $request->user()->id);
                } catch (RuntimeException $exception) {
                    return back()->with('warning', $exception->getMessage());
                }
            }

            $created++;
        }

        $loan = $installments->first()->loan;

        return redirect()
            ->route('loans.show', $loan)
            ->with('status', $this->shouldApplyImmediately($request) ? $created.' letra(s) marcadas como pagadas y aplicadas.' : $created.' letra(s) marcadas como pagadas; apareceran al generar corte.');
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

    private function shouldApplyImmediately(Request $request): bool
    {
        return $request->user()->can('payments.confirm') && ! $request->user()->hasRole('operador-cartera');
    }
}
