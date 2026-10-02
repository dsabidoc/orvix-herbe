<?php

namespace App\Http\Controllers;

use App\Domain\Collections\PeriodCollectionService;
use App\Domain\Cuts\WeeklyCutPeriodService;
use App\Domain\Loans\InstallmentPaymentPolicy;
use App\Domain\Loans\InterestOnlyScheduleExtender;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Operator;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        abort_if($this->isInvestorReadOnly($request), 403);

        app(InterestOnlyScheduleExtender::class)->ensureCoverageForScope($request);

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
            ->with(['loan.client', 'loan.operator', 'loan.vehicle', 'reportedMovement', 'allocations.movement'])
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
        $monthLoanIds = (clone $monthInstallments)->select('loan_id')->distinct()->pluck('loan_id');
        $collectedMonthLettersCents = app(PeriodCollectionService::class)
            ->amountForLoans($monthLoanIds, $monthStart, $monthEnd);
        $collectedMonthCents = WeeklyCut::query()
            ->where('status', 'closed')
            ->whereNotNull('confirmed_at')
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id))
            ->when(! $request->user()->hasRole('operador-cartera') && $operatorId, fn ($query) => $query->where('operator_id', $operatorId))
            ->whereBetween('confirmed_at', [$monthStart, $monthEnd->endOfDay()])
            ->get(['confirmed_total'])
            ->sum(fn (WeeklyCut $cut) => Money::cents($cut->confirmed_total));
        $pendingMonthCents = $monthOperationalCents - $collectedMonthLettersCents;
        $overdueCents = Installment::query()
            ->whereHas('loan', $loanScope)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereDate('due_date', '<', now('America/Merida')->toDateString())
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount') * 100;

        return view('collections.index', [
            'installments' => $installments,
            'kpis' => [
                ['title' => 'Cartera del mes', 'value' => Money::mxn(Money::decimal((int) ((clone $monthInstallments)->sum('remaining_amount') * 100))), 'caption' => 'Saldo de letras del mes', 'color' => 'blue'],
                ['title' => 'Letras cobradas del mes', 'value' => Money::mxn(Money::decimal((int) $collectedMonthLettersCents)), 'caption' => 'Pagos de letras con vencimiento en este mes', 'color' => 'orange'],
                ['title' => 'Esperado del mes', 'value' => Money::mxn(Money::decimal((int) $monthOperationalCents)), 'caption' => 'Calendario mensual', 'color' => 'yellow'],
                ['title' => 'Cobrado del mes', 'value' => Money::mxn(Money::decimal((int) $collectedMonthCents)), 'caption' => 'Pagos reportados o aplicados', 'color' => 'green'],
                ['title' => 'Pendiente por cobrar', 'value' => Money::mxn(Money::decimal((int) $pendingMonthCents)), 'caption' => 'Esperado menos letras cobradas', 'color' => 'orange'],
                ['title' => 'Vencido', 'value' => Money::mxn(Money::decimal((int) $overdueCents)), 'caption' => 'Letras vencidas del mes', 'color' => 'red'],
            ],
            'month' => $selectedMonth,
            'operators' => $request->user()->hasRole('operador-cartera')
                ? collect([$request->user()->operatorProfile])->filter()
                : Operator::query()->where('status', 'active')->orderBy('name')->get(),
            'selectedOperatorId' => $operatorId,
        ]);
    }

    public function markPaid(
        Request $request,
        Installment $installment,
        WeeklyCutPeriodService $cutPeriodService,
        PaymentApplicationService $paymentApplicationService,
    ): RedirectResponse|JsonResponse {
        $installment->load('loan.operator');
        $this->authorizeInstallmentAccess($request, $installment);

        if (Money::cents($installment->remaining_amount) <= 0) {
            if ($request->expectsJson() && $request->input('return_to') === 'cut') {
                return response()->json(['message' => 'Esta letra ya esta cubierta.'], 409);
            }

            return back()->with('warning', 'Esta letra ya esta cubierta.');
        }

        $existing = CollectionMovement::query()
            ->where('target_installment_id', $installment->id)
            ->where('confirmation_status', 'reported')
            ->first();

        if ($existing) {
            if ($request->expectsJson() && $request->input('return_to') === 'cut') {
                return response()->json(['message' => 'Esta letra ya tiene un pago por confirmar; espera a que se aplique antes de registrar otro abono.'], 409);
            }

            return back()->with('warning', 'Esta letra ya tiene un pago por confirmar; espera a que se aplique antes de registrar otro abono.');
        }

        $data = $request->validate([
            'operated_on' => ['required', 'date'],
            'contract_amount' => ['required', 'numeric', 'min:0.01'],
            'operator_surcharge_amount' => ['nullable', 'numeric', 'min:0'],
            'external_concepts_amount' => ['nullable', 'numeric', 'min:0'],
            'additional_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'delinquency_amount' => ['nullable', 'numeric', 'min:0'],
            'include_month_interest' => ['nullable', 'boolean'],
            'affects_investors' => ['nullable', 'boolean'],
            'payment_effect' => ['nullable', 'in:normal,no_investors,capital_advance'],
            'notes' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:20'],
            'return_month' => ['nullable', 'date_format:Y-m'],
            'cut_id' => ['nullable', 'exists:weekly_cuts,id'],
            'advance_loan_id' => ['nullable', 'exists:loans,id'],
        ]);

        $paymentEffect = $data['payment_effect'] ?? ((bool) ($data['affects_investors'] ?? true) ? 'normal' : 'no_investors');
        $paymentPolicy = app(InstallmentPaymentPolicy::class);
        $selectedCut = null;
        if (($data['return_to'] ?? null) === 'cut') {
            abort_unless($request->user()->can('weekly-cuts.confirm'), 403);
            abort_if(empty($data['cut_id']), 422, 'Selecciona el corte a ajustar.');
            $selectedCut = WeeklyCut::query()->findOrFail($data['cut_id']);
            abort_if($selectedCut->status === 'closed', 422, 'No se pueden registrar cobros en un corte cerrado.');
            abort_if($selectedCut->operator_id !== $installment->loan->operator_id, 422, 'El cobro no pertenece al operador de este corte.');
            $data['operated_on'] = $selectedCut->period_starts_on->toDateString();
        }

        $automaticCapitalOnly = ($installment->loan->calculation_method ?? 'regular') !== 'interest_only'
            && $paymentPolicy->isFutureMonth($installment, $data['operated_on']);
        $automaticInterestOnlyAdvance = ($installment->loan->calculation_method ?? 'regular') === 'interest_only'
            && $paymentPolicy->isFutureMonth($installment, $data['operated_on']);
        $includeMonthInterest = $request->boolean('include_month_interest');
        $isRegularInstallment = ($installment->loan->calculation_method ?? 'regular') !== 'interest_only';
        abort_if(
            $includeMonthInterest && ! $isRegularInstallment,
            422,
            'Los intereses del mes solo se pueden contemplar en una letra regular.',
        );
        if ($includeMonthInterest) {
            $paymentEffect = 'normal';
        }
        $movementType = $automaticInterestOnlyAdvance
            ? 'advance'
            : (($automaticCapitalOnly && ! $includeMonthInterest) || ($paymentEffect === 'capital_advance' && ! $includeMonthInterest)
                ? 'capital_advance'
                : 'ordinary');

        $principalRemainingCents = $paymentPolicy->principalRemainingCents($installment);
        $interestOnlyCapitalCents = $automaticInterestOnlyAdvance
            ? Money::cents($installment->loan->installments()->where('remaining_amount', '>', 0)->orderBy('number')->value('capital_balance') ?? $installment->loan->capital)
            : 0;
        $maximumCapitalCents = $automaticInterestOnlyAdvance ? $interestOnlyCapitalCents : $principalRemainingCents;
        $contractAmountCents = in_array($movementType, ['advance', 'capital_advance'], true)
            ? min(Money::cents($data['contract_amount']), $maximumCapitalCents)
            : Money::cents($data['contract_amount']);
        abort_if(
            in_array($movementType, ['advance', 'capital_advance'], true)
                && Money::cents($data['contract_amount']) > $maximumCapitalCents,
            422,
            'El abono a capital no puede exceder el capital pendiente de esta letra.',
        );
        abort_if(
            ! in_array($movementType, ['advance', 'capital_advance'], true) && $contractAmountCents > Money::cents($installment->remaining_amount),
            422,
            'El monto recibido no puede exceder el saldo pendiente de esta letra.',
        );
        abort_if(
            $includeMonthInterest && $contractAmountCents !== Money::cents($installment->remaining_amount),
            422,
            'Para contemplar intereses del mes debes cubrir el total pendiente de esta letra.',
        );
        $delinquencyAmountCents = ($includeMonthInterest && $automaticCapitalOnly) || in_array($movementType, ['advance', 'capital_advance'], true) || $paymentEffect === 'no_investors'
            ? 0
            : Money::cents($data['delinquency_amount'] ?? 0);

        abort_if($contractAmountCents <= 0, 422, 'Esta letra no tiene abono a capital disponible.');

        $registeredAt = now(WeeklyCutPeriodService::TIMEZONE);
        $movementPublicId = (string) Str::ulid();
        $movement = CollectionMovement::query()->create([
            'public_id' => $movementPublicId,
            'folio' => $this->nextMovementFolio($registeredAt),
            'idempotency_key' => sha1('installment|'.$installment->id.'|'.$data['operated_on'].'|'.$paymentEffect.'|'.Money::decimal($contractAmountCents).'|'.$movementPublicId),
            'loan_id' => $installment->loan_id,
            'target_installment_id' => $installment->id,
            'operator_id' => $installment->loan->operator_id,
            'registered_by' => $request->user()->id,
            'operated_on' => $data['operated_on'],
            'registered_at' => $registeredAt,
            'contract_amount' => Money::decimal($contractAmountCents),
            'operator_surcharge_amount' => Money::decimal(Money::cents($data['operator_surcharge_amount'] ?? 0)),
            'external_concepts_amount' => Money::decimal(Money::cents($data['external_concepts_amount'] ?? 0)),
            'additional_charge_amount' => Money::decimal(Money::cents($data['additional_charge_amount'] ?? 0)),
            'delinquency_amount' => Money::decimal($delinquencyAmountCents),
            'affects_investors' => $paymentEffect !== 'no_investors',
            'origin_weekly_cut_id' => $paymentEffect === 'no_investors' ? null : $selectedCut?->id,
            'type' => $movementType,
            'payment_method' => 'cash',
            'reference' => $includeMonthInterest && $automaticCapitalOnly ? PaymentApplicationService::FUTURE_MONTH_INTEREST_REFERENCE : null,
            'notes' => $data['notes'] ?? ($includeMonthInterest
                ? ($automaticCapitalOnly
                    ? 'Letra futura liquidada con capital e intereses del mes desde cobranza'
                    : 'Letra liquidada completa con intereses del mes desde cobranza')
                : ($automaticCapitalOnly || $automaticInterestOnlyAdvance
                ? 'Pago de letra futura aplicado solo a capital'
                : ($paymentEffect === 'capital_advance' ? 'Marcado como abono a capital desde cobranza' : 'Marcado pagado desde cobranza'))),
            'confirmation_status' => 'reported',
        ]);

        if ($paymentEffect === 'no_investors') {
            $paymentApplicationService->confirm($movement, $request->user()->id);
        } elseif (($data['return_to'] ?? null) === 'cut' && WeeklyCutPeriodService::isReportableMovement($movement)) {
            $cutPeriodService->attachMovementToCut(
                $movement,
                $selectedCut,
                $request->user()->id,
            );
        } else {
            $cutPeriodService->attachMovementToOpenCutForOperatedDate($movement, $request->user()->id);
        }

        $route = match ($data['return_to'] ?? '') {
            'loan' => route('loans.show', $installment->loan).'#installment-'.$installment->id,
            'dashboard' => route('dashboard'),
            'cut' => $this->cutReturnRoute($data),
            default => route('collections.index', [
                'month' => $data['return_month'] ?? now('America/Merida')->format('Y-m'),
                'operator_id' => $installment->loan->operator_id,
            ]),
        };

        if ($request->expectsJson() && ($data['return_to'] ?? null) === 'cut' && $selectedCut) {
            $selectedCut->refresh();
            $pendingDeliveryCents = max(0, Money::cents($selectedCut->reported_total) - Money::cents($selectedCut->received_total));
            $itemCount = $selectedCut->items()
                ->with('movement')
                ->get()
                ->filter(fn ($item) => $item->movement && WeeklyCutPeriodService::isReportableMovement($item->movement))
                ->count();

            return response()->json([
                'message' => $paymentEffect === 'no_investors'
                    ? 'Letra marcada como pagada sin efectos y aplicada directamente.'
                    : 'Letra marcada como pagada y agregada a este corte.',
                'installment_id' => $installment->id,
                'cut' => [
                    'reported_total' => Money::mxn($selectedCut->reported_total),
                    'confirmed_total' => Money::mxn($selectedCut->confirmed_total),
                    'received_total' => Money::mxn($selectedCut->received_total),
                    'difference_total' => Money::mxn($selectedCut->difference_total),
                    'pending_delivery' => Money::mxn(Money::decimal($pendingDeliveryCents)),
                    'items_count' => $itemCount,
                ],
            ]);
        }

        return redirect($route)->with('status', ($paymentEffect === 'no_investors'
                ? 'Letra marcada como pagada sin efectos y aplicada directamente.'
                : ((($data['return_to'] ?? null) === 'cut' && WeeklyCutPeriodService::isReportableMovement($movement))
                ? 'Letra marcada como pagada y agregada a este corte.'
                : 'Letra marcada como pagada y enviada al corte de la fecha seleccionada.')));
    }

    public function markPaidBulk(
        Request $request,
        WeeklyCutPeriodService $cutPeriodService,
        PaymentApplicationService $paymentApplicationService,
    ): RedirectResponse {
        $data = $request->validate([
            'installment_ids' => ['required', 'array', 'min:1', 'max:80'],
            'installment_ids.*' => ['integer', 'exists:installments,id'],
            'loan_id' => ['required', 'exists:loans,id'],
            'operated_on' => ['required', 'date'],
            'affects_investors' => ['nullable', 'boolean'],
            'payment_effect' => ['nullable', 'in:normal,no_investors,capital_advance'],
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
        $appliedDirectly = 0;
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

            $paymentEffect = $data['payment_effect'] ?? ((bool) ($data['affects_investors'] ?? true) ? 'normal' : 'no_investors');
            $paymentPolicy = app(InstallmentPaymentPolicy::class);
            $automaticCapitalOnly = ($installment->loan->calculation_method ?? 'regular') !== 'interest_only'
                && $paymentPolicy->isFutureMonth($installment, $data['operated_on']);
            $automaticInterestOnlyAdvance = ($installment->loan->calculation_method ?? 'regular') === 'interest_only'
                && $paymentPolicy->isFutureMonth($installment, $data['operated_on']);
            $movementType = $automaticInterestOnlyAdvance
                ? 'advance'
                : ($automaticCapitalOnly || $paymentEffect === 'capital_advance' ? 'capital_advance' : 'ordinary');
            $contractAmountCents = $automaticInterestOnlyAdvance
                ? Money::cents($installment->remaining_amount)
                : ($movementType === 'capital_advance'
                    ? $paymentPolicy->principalRemainingCents($installment)
                    : Money::cents($installment->remaining_amount));
            // Los pagos masivos no permiten seleccionar un moratorio por letra.
            $delinquencyAmountCents = 0;

            if ($contractAmountCents <= 0) {
                continue;
            }

            $registeredAt = now(WeeklyCutPeriodService::TIMEZONE);
            $movementPublicId = (string) Str::ulid();
            $movement = CollectionMovement::query()->create([
                'public_id' => $movementPublicId,
                'folio' => $this->nextMovementFolio($registeredAt),
                'idempotency_key' => sha1('bulk-installment|'.$installment->id.'|'.$data['operated_on'].'|'.$paymentEffect.'|'.Money::decimal($contractAmountCents).'|'.$movementPublicId),
                'loan_id' => $installment->loan_id,
                'target_installment_id' => $installment->id,
                'operator_id' => $installment->loan->operator_id,
                'registered_by' => $request->user()->id,
                'operated_on' => $data['operated_on'],
                'registered_at' => $registeredAt,
                'contract_amount' => Money::decimal($contractAmountCents),
                'operator_surcharge_amount' => '0.00',
                'external_concepts_amount' => '0.00',
                'additional_charge_amount' => '0.00',
                'delinquency_amount' => Money::decimal($delinquencyAmountCents),
                'affects_investors' => $paymentEffect !== 'no_investors',
                'type' => $movementType,
                'payment_method' => 'cash',
                'notes' => $automaticCapitalOnly || $automaticInterestOnlyAdvance
                    ? 'Pago de letra futura aplicado solo a capital en bloque'
                    : ($paymentEffect === 'capital_advance'
                    ? 'Marcado como abono a capital en bloque desde calendario contractual'
                    : 'Marcado pagado en bloque desde calendario contractual'),
                'confirmation_status' => 'reported',
            ]);

            if ($paymentEffect === 'no_investors') {
                $paymentApplicationService->confirm($movement, $request->user()->id);
                $appliedDirectly++;
            } else {
                $cutPeriodService->attachMovementToOpenCutForOperatedDate($movement, $request->user()->id);
            }

            $created++;
        }

        $loan = $installments->first()->loan;

        return redirect()
            ->to(route('loans.show', $loan).'#calendar')
            ->with('status', $appliedDirectly > 0
                ? $created.' letra(s) marcadas como pagadas sin efectos y aplicadas directamente.'
                : $created.' letra(s) marcadas como pagadas; apareceran al generar corte.');
    }

    private function selectedOperatorId(Request $request): ?int
    {
        if ($request->user()->hasRole('operador-cartera')) {
            return $request->user()->operatorProfile?->id;
        }

        return $request->filled('operator_id') ? (int) $request->input('operator_id') : null;
    }

    private function nextMovementFolio(CarbonInterface $registeredAt): string
    {
        $prefix = 'MOV-'.$registeredAt->format('ymd').'-';
        $maxSequence = CollectionMovement::query()
            ->where('folio', 'like', $prefix.'%')
            ->pluck('folio')
            ->reduce(function (int $max, string $folio) use ($prefix): int {
                $sequence = preg_replace('/\D+/', '', substr($folio, strlen($prefix)));

                return $sequence === '' ? $max : max($max, (int) $sequence);
            }, 0);

        do {
            $folio = $prefix.str_pad((string) (++$maxSequence), 4, '0', STR_PAD_LEFT);
        } while (CollectionMovement::query()->where('folio', $folio)->exists());

        return $folio;
    }

    private function cutReturnRoute(array $data): string
    {
        $route = route('cuts.show', WeeklyCut::query()->findOrFail($data['cut_id']));

        if (filled($data['advance_loan_id'] ?? null)) {
            return $route.'?advance_loan_id='.(int) $data['advance_loan_id'].'#cut-advance-modal';
        }

        return $route.'#cut-pending-installments';
    }

    private function authorizeInstallmentAccess(Request $request, Installment $installment): void
    {
        abort_if($this->isInvestorReadOnly($request), 403);

        if ($request->user()->hasRole('operador-cartera') && $installment->loan->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }
    }

    private function isInvestorReadOnly(Request $request): bool
    {
        return $request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage');
    }

    private function shouldApplyImmediately(Request $request): bool
    {
        return false;
    }
}
