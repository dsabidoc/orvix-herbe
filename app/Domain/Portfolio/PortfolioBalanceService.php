<?php

namespace App\Domain\Portfolio;

use App\Models\Loan;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PortfolioBalanceService
{
    private const EXCLUDED_INSTALLMENT_STATUSES = [
        'cancelled',
        'canceled',
        'substituted',
        'condoned',
        'forgiven',
        'invalid',
        'void',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters, User $user): array
    {
        $cutoff = $this->cutoffDate($filters['cutoff_date'] ?? null);
        $upcomingEnd = $this->upcomingEnd($cutoff, (string) ($filters['upcoming_range'] ?? '30'));
        $loans = $this->loanQuery($filters, $user)->get();
        $installmentIds = $loans->flatMap(fn (Loan $loan) => $loan->installments->pluck('id'))->values();
        $allocationAmounts = $this->allocationAmountsByInstallment($installmentIds, $cutoff);
        $allocationCounts = $this->allocationCountsByInstallment($installmentIds);
        $allocationLastDates = $this->allocationLastDatesByInstallment($installmentIds, $cutoff);

        $loanRows = $loans
            ->map(fn (Loan $loan) => $this->loanRow($loan, $cutoff, $upcomingEnd, $allocationAmounts, $allocationCounts, $allocationLastDates))
            ->filter(fn (array $row) => $row['pending_cents'] > 0 || $row['inconsistencies'] !== [])
            ->filter(fn (array $row) => $this->passesDerivedFilters($row, $filters))
            ->values();

        $loanRows = $this->sortLoanRows($loanRows, $filters);
        $detailRows = $this->detailRows($loanRows);

        return [
            'cutoff' => $cutoff,
            'upcoming_end' => $upcomingEnd,
            'loan_rows' => $loanRows,
            'detail_rows' => $detailRows,
            'operator_rows' => $this->operatorRows($loanRows),
            'kpis' => $this->kpis($loanRows),
            'filters' => $filters,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function loanQuery(array $filters, User $user): Builder
    {
        $query = Loan::query()
            ->with([
                'client',
                'operator',
                'vehicle',
                'investments',
                'installments' => fn ($query) => $query->orderBy('number'),
                'movements' => fn ($query) => $query
                    ->where('confirmation_status', 'applied')
                    ->orderByDesc('operated_on'),
            ])
            ->whereNotIn('status', ['cancelled', 'canceled']);

        if ($user->hasRole('operador-cartera')) {
            $query->where('operator_id', $user->operatorProfile?->id);
        } elseif (($filters['operator_id'] ?? null) === 'none') {
            $query->whereNull('operator_id');
        } elseif (! empty($filters['operator_id'])) {
            $query->where('operator_id', (int) $filters['operator_id']);
        }

        if (! empty($filters['investor_id'])) {
            $query->whereHas('investments', fn (Builder $query) => $query
                ->where('investor_id', (int) $filters['investor_id'])
                ->where('status', 'active'));
        }

        if (! empty($filters['loan_status'])) {
            $query->where('status', (string) $filters['loan_status']);
        }

        if (! empty($filters['payment_day'])) {
            $query->where('payment_day', (int) $filters['payment_day']);
        }

        if (! empty($filters['q'])) {
            $search = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $query) use ($search) {
                $query->where('folio', 'like', $search)
                    ->orWhereHas('client', fn (Builder $query) => $query
                        ->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('phone', 'like', $search))
                    ->orWhereHas('operator', fn (Builder $query) => $query->where('name', 'like', $search))
                    ->orWhereHas('vehicle', fn (Builder $query) => $query
                        ->where('brand', 'like', $search)
                        ->orWhere('model', 'like', $search)
                        ->orWhere('plates', 'like', $search)
                        ->orWhere('vin', 'like', $search));
            });
        }

        return $query;
    }

    /**
     * @param  Collection<int, int>  $installmentIds
     * @return array<int, int>
     */
    private function allocationAmountsByInstallment(Collection $installmentIds, CarbonImmutable $cutoff): array
    {
        if ($installmentIds->isEmpty()) {
            return [];
        }

        return PaymentAllocation::query()
            ->selectRaw('payment_allocations.installment_id, sum(payment_allocations.amount) as amount')
            ->join('collection_movements', 'collection_movements.id', '=', 'payment_allocations.collection_movement_id')
            ->whereIn('payment_allocations.installment_id', $installmentIds)
            ->where('collection_movements.confirmation_status', 'applied')
            ->whereDate('collection_movements.operated_on', '<=', $cutoff->toDateString())
            ->groupBy('payment_allocations.installment_id')
            ->pluck('amount', 'installment_id')
            ->map(fn ($amount) => Money::cents($amount))
            ->all();
    }

    /**
     * @param  Collection<int, int>  $installmentIds
     * @return array<int, int>
     */
    private function allocationCountsByInstallment(Collection $installmentIds): array
    {
        if ($installmentIds->isEmpty()) {
            return [];
        }

        return PaymentAllocation::query()
            ->whereIn('installment_id', $installmentIds)
            ->selectRaw('installment_id, count(*) as allocation_count')
            ->groupBy('installment_id')
            ->pluck('allocation_count', 'installment_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int, int>  $installmentIds
     * @return array<int, string>
     */
    private function allocationLastDatesByInstallment(Collection $installmentIds, CarbonImmutable $cutoff): array
    {
        if ($installmentIds->isEmpty()) {
            return [];
        }

        return PaymentAllocation::query()
            ->selectRaw('payment_allocations.installment_id, max(collection_movements.operated_on) as operated_on')
            ->join('collection_movements', 'collection_movements.id', '=', 'payment_allocations.collection_movement_id')
            ->whereIn('payment_allocations.installment_id', $installmentIds)
            ->where('collection_movements.confirmation_status', 'applied')
            ->whereDate('collection_movements.operated_on', '<=', $cutoff->toDateString())
            ->groupBy('payment_allocations.installment_id')
            ->pluck('operated_on', 'installment_id')
            ->map(fn ($date) => CarbonImmutable::parse($date)->format('d/m/Y'))
            ->all();
    }

    /**
     * @param  array<int, int>  $allocationAmounts
     * @param  array<int, int>  $allocationCounts
     * @param  array<int, string>  $allocationLastDates
     * @return array<string, mixed>
     */
    private function loanRow(Loan $loan, CarbonImmutable $cutoff, CarbonImmutable $upcomingEnd, array $allocationAmounts, array $allocationCounts, array $allocationLastDates): array
    {
        $installmentRows = $loan->installments
            ->map(fn ($installment) => $this->installmentRow($installment, $loan, $cutoff, $upcomingEnd, $allocationAmounts, $allocationCounts, $allocationLastDates))
            ->values();

        $pendingRows = $installmentRows->filter(fn (array $row) => $row['pending_cents'] > 0 && ! $row['is_excluded']);
        $overdueRows = $pendingRows->filter(fn (array $row) => $row['is_overdue']);
        $todayRows = $pendingRows->filter(fn (array $row) => $row['is_due_today']);
        $upcomingRows = $pendingRows->filter(fn (array $row) => $row['is_upcoming']);
        $nextInstallment = $pendingRows->sortBy([['due_date_sort', 'asc'], ['number', 'asc']])->first();
        $lastPaymentDate = $loan->movements
            ->first(fn ($movement) => CarbonImmutable::parse($movement->operated_on)->lte($cutoff))
            ?->operated_on
            ?->toDateString();
        $inconsistencies = $installmentRows
            ->flatMap(fn (array $row) => $row['inconsistencies'])
            ->values()
            ->all();

        if ($loan->operator_id === null) {
            $inconsistencies[] = 'Prestamo sin operador asignado.';
        }

        if ($loan->vehicle_id === null) {
            $inconsistencies[] = 'Prestamo sin vehiculo.';
        }

        if ($loan->status !== 'active' && $pendingRows->sum('pending_cents') > 0) {
            $inconsistencies[] = 'Prestamo con estado '.$loan->status.' y saldo pendiente.';
        }

        $maxLateDays = (int) $overdueRows->max('late_days');
        $state = $this->collectionState($maxLateDays, (int) $overdueRows->sum('overdue_cents'));

        return [
            'loan_id' => $loan->id,
            'loan_public_id' => $loan->public_id,
            'folio' => $loan->folio,
            'operator_id' => $loan->operator_id,
            'operator_key' => $loan->operator_id ? (string) $loan->operator_id : 'none',
            'operator_name' => $loan->operator?->name ?? 'Sin operador asignado',
            'client_id' => $loan->client_id,
            'client_name' => trim(($loan->client?->first_name ?? 'Sin cliente').' '.($loan->client?->last_name ?? '')),
            'vehicle_id' => $loan->vehicle_id,
            'vehicle_name' => trim(($loan->vehicle?->brand ? $loan->vehicle->brand.' ' : '').($loan->vehicle?->model ?? 'Sin vehiculo').' '.($loan->vehicle?->year ?? '')),
            'vehicle_identifier' => $loan->vehicle?->plates ?: $loan->vehicle?->vin,
            'term_months' => (int) $loan->term_months,
            'payment_day' => (int) $loan->payment_day,
            'next_installment_number' => $nextInstallment['number'] ?? null,
            'next_due_date' => $nextInstallment['due_date'] ?? null,
            'next_due_date_sort' => $nextInstallment['due_date_sort'] ?? '9999-12-31',
            'next_amount_cents' => $nextInstallment['pending_cents'] ?? 0,
            'pending_installments_count' => $pendingRows->count(),
            'overdue_installments_count' => $overdueRows->count(),
            'due_today_installments_count' => $todayRows->count(),
            'upcoming_installments_count' => $upcomingRows->count(),
            'overdue_cents' => (int) $overdueRows->sum('overdue_cents'),
            'pending_cents' => (int) $pendingRows->sum('pending_cents'),
            'due_today_cents' => (int) $todayRows->sum('pending_cents'),
            'upcoming_cents' => (int) $upcomingRows->sum('pending_cents'),
            'max_late_days' => $maxLateDays,
            'last_payment_date' => $lastPaymentDate,
            'loan_status' => $loan->status,
            'loan_status_label' => $this->loanStatusLabel((string) $loan->status),
            'collection_state' => $state,
            'installments' => $installmentRows,
            'inconsistencies' => array_values(array_unique($inconsistencies)),
        ];
    }

    /**
     * @param  array<int, int>  $allocationAmounts
     * @param  array<int, int>  $allocationCounts
     * @param  array<int, string>  $allocationLastDates
     * @return array<string, mixed>
     */
    private function installmentRow($installment, Loan $loan, CarbonImmutable $cutoff, CarbonImmutable $upcomingEnd, array $allocationAmounts, array $allocationCounts, array $allocationLastDates): array
    {
        $contractCents = Money::cents($installment->contract_amount);
        $hasAllocations = ($allocationCounts[$installment->id] ?? 0) > 0;
        $appliedCents = $hasAllocations
            ? (int) ($allocationAmounts[$installment->id] ?? 0)
            : Money::cents($installment->applied_amount);
        $rawPendingCents = $contractCents - $appliedCents;
        $pendingCents = max(0, $rawPendingCents);
        $dueDate = CarbonImmutable::parse($installment->due_date, 'America/Merida')->startOfDay();
        $isExcluded = in_array((string) $installment->status, self::EXCLUDED_INSTALLMENT_STATUSES, true);
        $lateDays = $dueDate->lt($cutoff) ? (int) $dueDate->diffInDays($cutoff) : 0;
        $isOverdue = ! $isExcluded && $dueDate->lt($cutoff) && $pendingCents > 0;
        $isDueToday = ! $isExcluded && $dueDate->equalTo($cutoff) && $pendingCents > 0;
        $isUpcoming = ! $isExcluded && $dueDate->gt($cutoff) && $dueDate->lte($upcomingEnd) && $pendingCents > 0;
        $inconsistencies = [];

        if ($rawPendingCents < 0) {
            $inconsistencies[] = "Pagare {$installment->number}: pago aplicado mayor al importe.";
        }

        if (in_array((string) $installment->status, ['confirmed', 'advanced', 'paid'], true) && $pendingCents > 0) {
            $inconsistencies[] = "Pagare {$installment->number}: estado pagado con saldo pendiente.";
        }

        if (! in_array((string) $installment->status, ['confirmed', 'advanced', 'paid'], true) && $pendingCents === 0 && ! $isExcluded) {
            $inconsistencies[] = "Pagare {$installment->number}: saldo cero con estado pendiente.";
        }

        return [
            'id' => $installment->id,
            'loan_id' => $loan->id,
            'number' => (int) $installment->number,
            'progress' => $installment->number.'/'.$loan->term_months,
            'due_date' => $dueDate->format('d/m/Y'),
            'due_date_sort' => $dueDate->toDateString(),
            'contract_cents' => $contractCents,
            'paid_cents' => max(0, $appliedCents),
            'pending_cents' => $pendingCents,
            'late_days' => $lateDays,
            'overdue_cents' => $isOverdue ? $pendingCents : 0,
            'status' => $this->installmentState($isExcluded, $pendingCents, $appliedCents, $isOverdue, $isDueToday, $dueDate, $cutoff, (string) $installment->status),
            'last_payment_date' => $allocationLastDates[$installment->id] ?? null,
            'is_excluded' => $isExcluded,
            'is_overdue' => $isOverdue,
            'is_due_today' => $isDueToday,
            'is_upcoming' => $isUpcoming,
            'inconsistencies' => $inconsistencies,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $loanRows
     * @return Collection<int, array<string, mixed>>
     */
    private function detailRows(Collection $loanRows): Collection
    {
        return $loanRows
            ->flatMap(function (array $loanRow) {
                return collect($loanRow['installments'])
                    ->filter(fn (array $installment) => $installment['pending_cents'] > 0 && ! $installment['is_excluded'])
                    ->map(fn (array $installment) => [
                        'loan_id' => $loanRow['loan_id'],
                        'loan_public_id' => $loanRow['loan_public_id'],
                        'folio' => $loanRow['folio'],
                        'operator_id' => $loanRow['operator_id'],
                        'operator_key' => $loanRow['operator_key'],
                        'operator_name' => $loanRow['operator_name'],
                        'client_id' => $loanRow['client_id'],
                        'client_name' => $loanRow['client_name'],
                        'vehicle_name' => $loanRow['vehicle_name'],
                        'vehicle_identifier' => $loanRow['vehicle_identifier'],
                        'term_months' => $loanRow['term_months'],
                        'installment_number' => $installment['number'],
                        'payment_progress' => $installment['progress'],
                        'payment_cents' => $installment['pending_cents'],
                        'due_date' => $installment['due_date'],
                        'due_date_sort' => $installment['due_date_sort'],
                        'late_days' => $installment['late_days'],
                        'overdue_installments_count' => $loanRow['overdue_installments_count'],
                        'overdue_cents' => $loanRow['overdue_cents'],
                    ]);
            })
            ->sort(fn (array $a, array $b) => strcmp($a['due_date_sort'], $b['due_date_sort'])
                ?: strnatcasecmp($a['client_name'], $b['client_name'])
                ?: ($a['installment_number'] <=> $b['installment_number']))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function passesDerivedFilters(array $row, array $filters): bool
    {
        $mode = (string) ($filters['mode'] ?? 'complete');

        if ($mode === 'overdue' && $row['overdue_installments_count'] === 0) {
            return false;
        }

        if ($mode === 'current' && ($row['overdue_installments_count'] > 0 || $row['pending_cents'] === 0)) {
            return false;
        }

        if ($mode === 'due_today' && $row['due_today_cents'] === 0) {
            return false;
        }

        if ($mode === 'upcoming' && $row['upcoming_cents'] === 0) {
            return false;
        }

        if (($filters['overdue_presence'] ?? null) === 'with' && $row['overdue_installments_count'] === 0) {
            return false;
        }

        if (($filters['overdue_presence'] ?? null) === 'without' && $row['overdue_installments_count'] > 0) {
            return false;
        }

        if (! $this->passesLateRange($row['max_late_days'], (string) ($filters['late_range'] ?? ''))) {
            return false;
        }

        $overdueMin = $filters['overdue_min'] ?? null;
        $overdueMax = $filters['overdue_max'] ?? null;

        if ($overdueMin !== null && $overdueMin !== '' && $row['overdue_cents'] < Money::cents($overdueMin)) {
            return false;
        }

        if ($overdueMax !== null && $overdueMax !== '' && $row['overdue_cents'] > Money::cents($overdueMax)) {
            return false;
        }

        return true;
    }

    private function passesLateRange(int $days, string $range): bool
    {
        return match ($range) {
            '0' => $days === 0,
            '1-7' => $days >= 1 && $days <= 7,
            '8-30' => $days >= 8 && $days <= 30,
            '31-60' => $days >= 31 && $days <= 60,
            '61-90' => $days >= 61 && $days <= 90,
            '90+' => $days > 90,
            default => true,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortLoanRows(Collection $rows, array $filters): Collection
    {
        $sort = (string) ($filters['sort'] ?? '');
        $direction = (string) ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($sort !== '') {
            $callback = match ($sort) {
                'operator' => fn (array $row) => $row['operator_name'],
                'client' => fn (array $row) => $row['client_name'],
                'next_due' => fn (array $row) => $row['next_due_date_sort'],
                'overdue_count' => fn (array $row) => $row['overdue_installments_count'],
                'max_late_days' => fn (array $row) => $row['max_late_days'],
                'overdue_balance' => fn (array $row) => $row['overdue_cents'],
                'pending_balance' => fn (array $row) => $row['pending_cents'],
                default => null,
            };

            if ($callback) {
                return ($direction === 'desc' ? $rows->sortByDesc($callback) : $rows->sortBy($callback))->values();
            }
        }

        return $rows->sort(function (array $a, array $b) {
            return strnatcasecmp($a['operator_name'], $b['operator_name'])
                ?: ($b['max_late_days'] <=> $a['max_late_days'])
                ?: ($b['overdue_cents'] <=> $a['overdue_cents'])
                ?: strcmp($a['next_due_date_sort'], $b['next_due_date_sort']);
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $loanRows
     * @return Collection<int, array<string, mixed>>
     */
    private function operatorRows(Collection $loanRows): Collection
    {
        return $loanRows->groupBy('operator_key')->map(function (Collection $rows) {
            $first = $rows->first();
            $maxLateDays = (int) $rows->max('max_late_days');
            $overdueCents = (int) $rows->sum('overdue_cents');

            return [
                'operator_key' => $first['operator_key'],
                'operator_id' => $first['operator_id'],
                'operator_name' => $first['operator_name'],
                'clients_count' => $rows->pluck('client_id')->unique()->count(),
                'loans_count' => $rows->count(),
                'pending_installments_count' => (int) $rows->sum('pending_installments_count'),
                'overdue_installments_count' => (int) $rows->sum('overdue_installments_count'),
                'vehicles_with_overdue_count' => $rows->filter(fn (array $row) => $row['overdue_installments_count'] > 0)->count(),
                'overdue_cents' => $overdueCents,
                'pending_cents' => (int) $rows->sum('pending_cents'),
                'max_late_days' => $maxLateDays,
                'next_due_date' => $rows->where('next_due_date_sort', '!=', '9999-12-31')->min('next_due_date_sort'),
                'collection_state' => $this->collectionState($maxLateDays, $overdueCents),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $loanRows
     * @return array<int, array<string, mixed>>
     */
    private function kpis(Collection $loanRows): array
    {
        $pendingCents = (int) $loanRows->sum('pending_cents');
        $overdueCents = (int) $loanRows->sum('overdue_cents');
        $percent = $pendingCents > 0 ? round(($overdueCents / $pendingCents) * 100, 2) : 0;

        return [
            ['title' => 'Operadores', 'value' => (string) $loanRows->pluck('operator_key')->unique()->count(), 'caption' => 'Mostrados con filtros', 'color' => 'blue'],
            ['title' => 'Clientes', 'value' => (string) $loanRows->pluck('client_id')->unique()->count(), 'caption' => 'Clientes distintos', 'color' => 'slate'],
            ['title' => 'Vehiculos/prestamos', 'value' => (string) $loanRows->count(), 'caption' => 'Prestamos activos', 'color' => 'blue'],
            ['title' => 'Pagares pendientes', 'value' => (string) $loanRows->sum('pending_installments_count'), 'caption' => 'No liquidados', 'color' => 'yellow'],
            ['title' => 'Pagares vencidos', 'value' => (string) $loanRows->sum('overdue_installments_count'), 'caption' => 'Con saldo vencido', 'color' => 'red'],
            ['title' => 'Clientes vencidos', 'value' => (string) $loanRows->where('overdue_installments_count', '>', 0)->pluck('client_id')->unique()->count(), 'caption' => 'Clientes con atraso', 'color' => 'red'],
            ['title' => 'Vehiculos vencidos', 'value' => (string) $loanRows->where('overdue_installments_count', '>', 0)->count(), 'caption' => 'Prestamos con atraso', 'color' => 'orange'],
            ['title' => 'Saldo vencido', 'value' => Money::mxn(Money::decimal($overdueCents)), 'caption' => 'Pendiente vencido', 'color' => 'red'],
            ['title' => 'Saldo total', 'value' => Money::mxn(Money::decimal($pendingCents)), 'caption' => 'Pendiente total', 'color' => 'green'],
            ['title' => 'Vence hoy', 'value' => Money::mxn(Money::decimal((int) $loanRows->sum('due_today_cents'))), 'caption' => 'Fecha de corte', 'color' => 'yellow'],
            ['title' => 'Proximo', 'value' => Money::mxn(Money::decimal((int) $loanRows->sum('upcoming_cents'))), 'caption' => 'Rango seleccionado', 'color' => 'orange'],
            ['title' => '% cartera vencida', 'value' => number_format($percent, 2).'%', 'caption' => 'Vencido / total pendiente', 'color' => $percent > 0 ? 'red' : 'green'],
        ];
    }

    /**
     * @return array{label:string,class:string}
     */
    private function collectionState(int $maxLateDays, int $overdueCents): array
    {
        if ($overdueCents <= 0 || $maxLateDays === 0) {
            return ['label' => 'Al corriente', 'class' => 'bg-emerald-50 text-emerald-700'];
        }

        return match (true) {
            $maxLateDays <= 7 => ['label' => 'Atraso inicial', 'class' => 'bg-amber-50 text-amber-700'],
            $maxLateDays <= 30 => ['label' => 'Atraso moderado', 'class' => 'bg-orange-50 text-orange-700'],
            $maxLateDays <= 60 => ['label' => 'Atraso importante', 'class' => 'bg-red-50 text-red-700'],
            $maxLateDays <= 90 => ['label' => 'Atraso grave', 'class' => 'bg-red-100 text-red-800'],
            default => ['label' => 'Atraso critico', 'class' => 'bg-slate-950 text-white'],
        };
    }

    /**
     * @return array{label:string,class:string}
     */
    private function installmentState(bool $isExcluded, int $pendingCents, int $appliedCents, bool $isOverdue, bool $isDueToday, CarbonImmutable $dueDate, CarbonImmutable $cutoff, string $status): array
    {
        if ($isExcluded) {
            return ['label' => 'Cancelado', 'class' => 'bg-slate-100 text-slate-600'];
        }

        if ($pendingCents === 0) {
            return ['label' => 'Pagado', 'class' => 'bg-emerald-50 text-emerald-700'];
        }

        if ($appliedCents > 0) {
            return ['label' => $isOverdue ? 'Parcial vencido' : 'Parcial', 'class' => $isOverdue ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'];
        }

        if ($isOverdue) {
            return ['label' => 'Vencido', 'class' => 'bg-red-50 text-red-700'];
        }

        if ($isDueToday) {
            return ['label' => 'Vence hoy', 'class' => 'bg-yellow-50 text-yellow-700'];
        }

        return ['label' => $dueDate->gt($cutoff) ? 'Proximo' : $this->loanStatusLabel($status), 'class' => 'bg-slate-100 text-slate-700'];
    }

    private function loanStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Activo',
            'settled' => 'Liquidado',
            'formalizing' => 'Formalizando',
            'cancelled', 'canceled' => 'Cancelado',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function cutoffDate(mixed $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date ?: CarbonImmutable::now('America/Merida')->toDateString(), 'America/Merida')->startOfDay();
    }

    private function upcomingEnd(CarbonImmutable $cutoff, string $range): CarbonImmutable
    {
        return match ($range) {
            '7' => $cutoff->addDays(7),
            '15' => $cutoff->addDays(15),
            'month' => $cutoff->endOfMonth(),
            default => $cutoff->addDays(30),
        };
    }
}
