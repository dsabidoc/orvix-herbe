<?php

namespace App\Http\Controllers;

use App\Domain\Loans\PaymentApplicationService;
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

        return view('cuts.show', [
            'cut' => $cut->load(['operator', 'items.movement.targetInstallment', 'items.movement.loan.client', 'items.movement.loan.vehicle']),
            'overdueInstallments' => $this->overdueInstallmentsForCut($cut),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->hasRole('operador-cartera')) {
            $data = $request->validate(['operator_id' => ['nullable', 'exists:operators,id']]);

            if (empty($data['operator_id'])) {
                $createdCuts = Operator::query()
                    ->where('status', 'active')
                    ->get()
                    ->map(fn (Operator $operator) => $this->createWeeklyCutForOperator($operator, $request))
                    ->filter();

                if ($createdCuts->isEmpty()) {
                    return back()->with('warning', 'No hay cobros reportados ni atrasados para esta semana.');
                }

                return redirect()->route('cuts.index')->with('status', 'Se generaron '.$createdCuts->count().' corte(s) semanal(es).');
            }
        }

        $operator = $request->user()->hasRole('operador-cartera')
            ? $request->user()->operatorProfile
            : Operator::query()->findOrFail($data['operator_id']);

        abort_unless($operator, 403);

        $cut = $this->createWeeklyCutForOperator($operator, $request);

        if (! $cut) {
            return back()->with('warning', 'No hay cobros reportados ni atrasados para esta semana.');
        }

        return redirect()->route('cuts.show', $cut)->with('status', 'Corte semanal enviado.');
    }

    private function createWeeklyCutForOperator(Operator $operator, Request $request): ?WeeklyCut
    {
        $today = CarbonImmutable::now('America/Merida');
        $start = $today->startOfWeek()->toDateString();
        $end = $today->endOfWeek()->toDateString();

        $existingCut = WeeklyCut::query()
            ->where('operator_id', $operator->id)
            ->where('period_starts_on', $start)
            ->where('period_ends_on', $end)
            ->exists();

        if ($existingCut) {
            return null;
        }

        $alreadyIncluded = DB::table('weekly_cut_items')->pluck('collection_movement_id');
        $movements = CollectionMovement::query()
            ->where('operator_id', $operator->id)
            ->where('confirmation_status', 'reported')
            ->whereBetween('operated_on', [$start, $end])
            ->whereNotIn('id', $alreadyIncluded)
            ->get();

        $overdueInstallments = $this->overdueInstallmentsForPeriod($operator->id, $start);

        if ($movements->isEmpty() && $overdueInstallments->isEmpty()) {
            return null;
        }

        $reportedCents = $movements->sum(fn (CollectionMovement $movement) => Money::cents($movement->contract_amount));
        $previousBalanceCents = $this->latestOperatorBalance($operator->id);
        $carriedShortfallCents = max(0, -$previousBalanceCents);
        $expectedCents = $reportedCents + $carriedShortfallCents;

        $cut = WeeklyCut::query()->create([
            'public_id' => (string) Str::ulid(),
            'operator_id' => $operator->id,
            'submitted_by' => $request->user()->id,
            'period_starts_on' => $start,
            'period_ends_on' => $end,
            'expected_total' => Money::decimal($expectedCents),
            'reported_total' => Money::decimal($reportedCents),
            'received_total' => '0.00',
            'difference_total' => Money::decimal(-$expectedCents),
            'previous_balance' => Money::decimal($previousBalanceCents),
            'status' => 'submitted',
            'submitted_at' => now('America/Merida'),
        ]);

        foreach ($movements as $movement) {
            $cut->items()->create([
                'collection_movement_id' => $movement->id,
                'expected_amount' => $movement->contract_amount,
                'reported_amount' => $movement->contract_amount,
                'received_amount' => '0.00',
                'status' => 'included',
            ]);
        }

        return $cut;
    }

    public function confirm(Request $request, WeeklyCut $cut, PaymentApplicationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('weekly-cuts.confirm'), 403);

        $data = $request->validate([
            'received_total' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($cut, $data, $request, $service) {
            $cut = WeeklyCut::query()->whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $receivedCents = Money::cents($data['received_total']);
            $reportedCents = Money::cents($cut->reported_total);
            $expectedCents = Money::cents($cut->expected_total);
            $previousBalanceCents = Money::cents($cut->previous_balance);
            $differenceCents = $receivedCents - $expectedCents;
            $balanceAfterCents = $previousBalanceCents + $receivedCents - $reportedCents;

            foreach ($cut->items()->with('movement')->get() as $item) {
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

            $cut->update([
                'confirmed_by' => $request->user()->id,
                'received_total' => Money::decimal($receivedCents),
                'difference_total' => Money::decimal($differenceCents),
                'accumulated_balance' => Money::decimal($balanceAfterCents),
                'status' => $differenceCents === 0 ? 'confirmed' : 'with_difference',
                'confirmed_at' => now('America/Merida'),
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
                'reason' => $data['reason'] ?? 'Confirmacion de corte semanal',
            ]);
        });

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
                'reason' => $data['reason'] ?? 'Liquidacion de saldo pendiente de corte semanal',
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
