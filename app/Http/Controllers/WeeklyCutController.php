<?php

namespace App\Http\Controllers;

use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Operator;
use App\Models\OperatorLedgerEntry;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class WeeklyCutController extends Controller
{
    public function index(Request $request): View
    {
        return view('cuts.index', [
            'cuts' => WeeklyCut::query()
                ->with('operator')
                ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
                ->latest()
                ->paginate(15),
            'operators' => Operator::query()->where('status', 'active')->get(),
        ]);
    }

    public function show(Request $request, WeeklyCut $cut): View
    {
        if ($request->user()->hasRole('operador-cartera') && $cut->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }

        $cut = app(WeeklyCutPeriodService::class)->refreshTotals($cut)->load([
            'operator',
            'submittedBy',
            'confirmedBy',
            'items.movement.registeredBy',
            'items.movement.targetInstallment',
            'items.movement.loan.client',
            'items.movement.loan.vehicle',
            'fundDisbursements.loan.client',
            'fundDisbursements.loan.vehicle',
            'fundDisbursements.registeredBy',
            'ledgerEntries',
        ]);
        $cut->setRelation(
            'items',
            $cut->items
                ->filter(fn ($item) => WeeklyCutPeriodService::isReportableMovement($item->movement))
                ->values(),
        );

        return view('cuts.show', [
            'cut' => $cut,
            'overdueInstallments' => $this->overdueInstallmentsForCut($cut),
        ]);
    }

    public function store(Request $request, WeeklyCutPeriodService $cutPeriodService): RedirectResponse
    {
        if (! $request->user()->hasRole('operador-cartera')) {
            $data = $request->validate([
                'operator_id' => ['nullable', 'exists:operators,id'],
                'cut_date' => ['required', 'date'],
            ]);

            if (empty($data['operator_id'])) {
                $createdCuts = Operator::query()
                    ->where('status', 'active')
                    ->get()
                    ->map(fn (Operator $operator) => $this->createWeeklyCutForOperator($operator, $request, $cutPeriodService, $data['cut_date']))
                    ->filter();

                if ($createdCuts->isEmpty()) {
                    return back()->with('warning', 'No hay operadores activos para generar cortes.');
                }

                return redirect()->route('cuts.index')->with('status', 'Se generaron '.$createdCuts->count().' corte(s).');
            }
        }

        $operator = $request->user()->hasRole('operador-cartera')
            ? $request->user()->operatorProfile
            : Operator::query()->findOrFail($data['operator_id']);

        abort_unless($operator, 403);

        $cutDate = $request->input('cut_date', now('America/Merida')->toDateString());
        $cut = $this->createWeeklyCutForOperator($operator, $request, $cutPeriodService, $cutDate);

        if (! $cut) {
            return back()->with('warning', 'No se pudo generar el corte.');
        }

        if ($cut->status === 'closed') {
            return redirect()
                ->route('cuts.show', $cut)
                ->with('warning', 'Ese corte ya esta cerrado. No se pueden agregar cobros nuevos a un corte cerrado.');
        }

        return redirect()->route('cuts.show', $cut)->with('status', 'Corte generado.');
    }

    private function createWeeklyCutForOperator(Operator $operator, Request $request, WeeklyCutPeriodService $cutPeriodService, ?string $cutDate = null): ?WeeklyCut
    {
        $date = CarbonImmutable::parse($cutDate ?: now('America/Merida')->toDateString(), 'America/Merida');
        $cut = $cutPeriodService->createCutForOperator($operator, $request->user()->id, $date);
        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();

        CollectionMovement::query()
            ->with('registeredBy')
            ->where('operator_id', $operator->id)
            ->whereNull('weekly_cut_id')
            ->where('affects_investors', true)
            ->whereIn('confirmation_status', WeeklyCutPeriodService::REPORTABLE_MOVEMENT_STATUSES)
            ->whereBetween(DB::raw('COALESCE(registered_at, created_at)'), [$dayStart, $dayEnd])
            ->get()
            ->filter(fn (CollectionMovement $movement) => WeeklyCutPeriodService::isReportableMovement($movement))
            ->each(fn (CollectionMovement $movement) => $cutPeriodService->attachMovementToCut($movement, $cut, $request->user()->id));

        return $cutPeriodService->refreshTotals($cut);
    }

    public function confirm(Request $request, WeeklyCut $cut, PaymentApplicationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        $data = $request->validate([
            'received_total' => ['required', 'numeric', 'min:0'],
            'received_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($cut, $data, $request, $service) {
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();
            abort_if($cut->status === 'closed', 422, 'No se puede confirmar un corte cerrado sin reabrirlo.');
            $receivedCents = Money::cents($data['received_total']);
            $reportedCents = Money::cents($cut->reported_total);
            $previousBalanceCents = Money::cents($cut->previous_balance);
            $differenceCents = $receivedCents - $reportedCents;
            $balanceAfterCents = $previousBalanceCents + $receivedCents - $reportedCents;
            $receivedAt = CarbonImmutable::parse($data['received_on'], 'America/Merida')->endOfDay();

            foreach ($cut->items()->with('movement.registeredBy')->whereHas('movement', fn ($query) => $query->whereIn('confirmation_status', WeeklyCutPeriodService::REPORTABLE_MOVEMENT_STATUSES))->get() as $item) {
                if (! WeeklyCutPeriodService::isReportableMovement($item->movement)) {
                    continue;
                }

                if ($item->movement->confirmation_status === 'reported') {
                    try {
                        $service->confirm($item->movement, $request->user()->id);
                    } catch (RuntimeException) {
                        // Keep confirming the cut totals; item-level issues stay visible on the movement.
                    }
                }

                $item->update([
                    'received_amount' => $item->reported_amount,
                    'status' => 'confirmed',
                ]);
            }

            OperatorLedgerEntry::query()
                ->where('weekly_cut_id', $cut->id)
                ->whereIn('type', ['confirmed_delivery', 'shortfall', 'overage'])
                ->delete();

            $cut->update([
                'confirmed_by' => $request->user()->id,
                'received_total' => Money::decimal($receivedCents),
                'confirmed_total' => Money::decimal($receivedCents),
                'difference_total' => Money::decimal($differenceCents),
                'accumulated_balance' => Money::decimal($balanceAfterCents),
                'status' => $differenceCents === 0 ? 'balanced' : ($receivedCents > 0 ? 'partially_received' : 'with_difference'),
                'confirmed_at' => $receivedAt,
                'balance_settled_at' => $balanceAfterCents === 0 ? $cut->balance_settled_at : null,
                'balance_settled_by' => $balanceAfterCents === 0 ? $cut->balance_settled_by : null,
            ]);

            OperatorLedgerEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $cut->operator_id,
                'weekly_cut_id' => $cut->id,
                'created_by' => $request->user()->id,
                'type' => $balanceAfterCents === 0 ? 'confirmed_delivery' : ($balanceAfterCents < 0 ? 'shortfall' : 'overage'),
                'amount' => Money::decimal(abs($balanceAfterCents - $previousBalanceCents)),
                'balance_before' => Money::decimal($previousBalanceCents),
                'balance_after' => Money::decimal($balanceAfterCents),
                'idempotency_key' => sha1('cut|'.$cut->id.'|'.$receivedCents),
                'reason' => $data['reason'] ?? 'Confirmacion de corte',
            ]);

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'weekly_cut.confirmed',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'after' => [
                    'reported_total' => $cut->reported_total,
                    'received_total' => Money::decimal($receivedCents),
                    'received_on' => $receivedAt->toDateString(),
                    'difference_total' => Money::decimal($differenceCents),
                ],
            ]);
        });

        app(WeeklyCutPeriodService::class)->refreshTotals($cut);

        return redirect()->route('cuts.show', $cut)->with('status', 'Corte confirmado y movimientos aplicados.');
    }

    public function settleBalance(Request $request, WeeklyCut $cut): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settled_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($cut, $data, $request) {
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $pendingOnCutCents = max(0, -Money::cents($cut->accumulated_balance));
            $amountCents = Money::cents($data['amount']);

            abort_if($cut->status === 'closed', 422, 'No se puede liquidar directamente un corte cerrado sin reabrirlo.');
            abort_if($pendingOnCutCents === 0, 422, 'Este corte no tiene saldo pendiente por liquidar.');
            abort_if($amountCents > $pendingOnCutCents, 422, 'El monto a liquidar no puede ser mayor al saldo pendiente.');

            $balanceBeforeCents = $this->latestOperatorBalance($cut->operator_id);
            $balanceAfterCents = $balanceBeforeCents + $amountCents;
            $cutBalanceAfterCents = -($pendingOnCutCents - $amountCents);

            OperatorLedgerEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'operator_id' => $cut->operator_id,
                'weekly_cut_id' => $cut->id,
                'created_by' => $request->user()->id,
                'type' => 'regularization',
                'amount' => Money::decimal($amountCents),
                'balance_before' => Money::decimal($balanceBeforeCents),
                'balance_after' => Money::decimal($balanceAfterCents),
                'idempotency_key' => sha1('settle-cut|'.$cut->id.'|'.$request->user()->id.'|'.$amountCents.'|'.$data['settled_on'].'|'.now('America/Merida')->timestamp),
                'reason' => $data['reason'] ?? 'Liquidacion de saldo pendiente de corte',
                'settled_at' => CarbonImmutable::parse($data['settled_on'], 'America/Merida')->endOfDay(),
                'settled_by' => $request->user()->id,
            ]);

            $cut->update([
                'regularization_total' => Money::decimal(Money::cents($cut->regularization_total) + $amountCents),
                'accumulated_balance' => Money::decimal($cutBalanceAfterCents),
                'balance_settled_at' => $cutBalanceAfterCents === 0 ? CarbonImmutable::parse($data['settled_on'], 'America/Merida')->endOfDay() : null,
                'balance_settled_by' => $cutBalanceAfterCents === 0 ? $request->user()->id : null,
            ]);
        });

        return redirect()->route('cuts.show', $cut)->with('status', 'Saldo pendiente liquidado y descontado del siguiente corte.');
    }

    public function close(Request $request, WeeklyCut $cut): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        DB::transaction(function () use ($cut, $request) {
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();
            abort_if($cut->status === 'closed', 422, 'Este corte ya esta cerrado.');

            $before = $cut->only(['status', 'closed_at', 'closed_by']);
            $cut->update([
                'status' => 'closed',
                'closed_at' => now('America/Merida'),
                'closed_by' => $request->user()->id,
            ]);

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'weekly_cut.closed',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'before' => $before,
                'after' => $cut->only(['status', 'closed_at', 'closed_by']),
            ]);
        });

        return redirect()->route('cuts.show', $cut)->with('status', 'Corte cerrado.');
    }

    public function reopen(Request $request, WeeklyCut $cut): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($cut, $request, $data) {
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();
            abort_unless($cut->status === 'closed', 422, 'Solo puedes reabrir cortes cerrados.');

            $before = $cut->only(['status', 'reopened_at', 'reopened_by', 'reopen_reason']);
            $cut->update([
                'status' => 'reopened',
                'reopened_at' => now('America/Merida'),
                'reopened_by' => $request->user()->id,
                'reopen_reason' => $data['reason'],
            ]);

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'weekly_cut.reopened',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'before' => $before,
                'after' => $cut->only(['status', 'reopened_at', 'reopened_by', 'reopen_reason']),
                'reason' => $data['reason'],
            ]);
        });

        return redirect()->route('cuts.show', $cut)->with('status', 'Corte reabierto.');
    }

    public function destroy(Request $request, WeeklyCut $cut): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        DB::transaction(function () use ($cut, $request): void {
            $cut = WeeklyCut::query()
                ->with(['items', 'fundDisbursements', 'ledgerEntries'])
                ->whereKey($cut->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $cut->only([
                'public_id',
                'operator_id',
                'period_starts_on',
                'period_ends_on',
                'settlement_on',
                'reported_total',
                'received_total',
                'confirmed_total',
                'difference_total',
                'status',
            ]);

            CollectionMovement::query()
                ->where('weekly_cut_id', $cut->id)
                ->update(['weekly_cut_id' => null]);

            CollectionMovement::query()
                ->where('origin_weekly_cut_id', $cut->id)
                ->update(['origin_weekly_cut_id' => null]);

            $cut->fundDisbursements()->update(['weekly_cut_id' => null]);
            $cut->ledgerEntries()->update(['weekly_cut_id' => null]);
            $cut->items()->delete();
            $cut->delete();

            AuditEvent::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'weekly_cut.deleted',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'before' => $before,
                'after' => [
                    'deleted_at' => now('America/Merida')->toDateTimeString(),
                    'movements_detached' => true,
                ],
            ]);
        });

        return redirect()->route('cuts.index')->with('status', 'Corte eliminado. Los cobros y movimientos relacionados se conservaron sin corte asignado.');
    }

    private function overdueInstallmentsForCut(WeeklyCut $cut)
    {
        return $this->overdueInstallmentsForPeriod($cut->operator_id, $cut->period_starts_on->toDateString());
    }

    private function overdueInstallmentsForPeriod(int $operatorId, string $periodStartsOn)
    {
        return Installment::query()
            ->with(['loan.client', 'loan.vehicle', 'reportedMovement'])
            ->where('remaining_amount', '>', 0)
            ->whereDate('due_date', '<', $periodStartsOn)
            ->whereDoesntHave('reportedMovement', fn ($query) => $query->whereIn('confirmation_status', ['reported', 'applied']))
            ->whereHas('loan', fn ($query) => $query->where('operator_id', $operatorId)->where('status', 'active'))
            ->orderBy('due_date')
            ->get();
    }

    private function latestOperatorBalance(int $operatorId): int
    {
        $latestBalance = OperatorLedgerEntry::query()
            ->where('operator_id', $operatorId)
            ->latest('id')
            ->value('balance_after');

        return Money::cents($latestBalance);
    }
}
