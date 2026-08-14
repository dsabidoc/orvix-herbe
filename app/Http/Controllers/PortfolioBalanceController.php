<?php

namespace App\Http\Controllers;

use App\Domain\Portfolio\PortfolioBalanceService;
use App\Models\AuditEvent;
use App\Models\Operator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioBalanceController extends Controller
{
    public function index(Request $request, PortfolioBalanceService $service): View
    {
        $this->authorizeAccess($request);

        $filters = $this->filters($request);
        $report = $service->build($filters, $request->user());
        $loanRows = $this->paginate($report['detail_rows'], $request);
        $selectedLoan = $request->filled('loan')
            ? $report['loan_rows']->firstWhere('loan_public_id', $request->string('loan')->toString())
            : null;

        $this->audit($request, 'portfolio_balance.viewed', $filters, [
            'rows' => $report['detail_rows']->count(),
            'cutoff_date' => $report['cutoff']->toDateString(),
        ]);

        return view('portfolio-balances.index', [
            'report' => $report,
            'loanRows' => $loanRows,
            'selectedLoan' => $selectedLoan,
            'operators' => $this->operators($request),
            'filters' => $filters,
        ]);
    }

    public function export(Request $request, PortfolioBalanceService $service): StreamedResponse
    {
        $this->authorizeAccess($request, export: true);

        $filters = $this->filters($request);
        $report = $service->build($filters, $request->user());
        $filename = 'cartera-y-saldos-'.$report['cutoff']->format('Y-m-d').'.csv';

        $this->audit($request, 'portfolio_balance.exported', $filters, [
            'rows' => $report['detail_rows']->count(),
            'cutoff_date' => $report['cutoff']->toDateString(),
        ]);

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Fecha de exportacion', CarbonImmutable::now('America/Merida')->format('d/m/Y H:i')]);
            fputcsv($handle, ['Corte', $report['cutoff']->format('d/m/Y')]);
            fputcsv($handle, []);

            fputcsv($handle, ['Resumen por operador']);
            fputcsv($handle, [
                'Operador',
                'Clientes',
                'Prestamos',
                'Pagares pendientes',
                'Pagares vencidos',
                'Vehiculos con atraso',
                'Saldo vencido',
                'Max atraso',
                'Estado',
            ]);

            foreach ($report['operator_rows'] as $operatorRow) {
                fputcsv($handle, [
                    $operatorRow['operator_name'],
                    $operatorRow['clients_count'],
                    $operatorRow['loans_count'],
                    $operatorRow['pending_installments_count'],
                    $operatorRow['overdue_installments_count'],
                    $operatorRow['vehicles_with_overdue_count'],
                    Money::decimal($operatorRow['overdue_cents']),
                    $operatorRow['max_late_days'].' dias',
                    $operatorRow['collection_state']['label'],
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Detalle de cartera']);
            fputcsv($handle, [
                'Modelo con dia / folio',
                'Num. pagare',
                'Pago',
                'Fecha pagare',
                'Dias de atraso',
                'Suma vencidas',
                'Cliente',
            ]);

            foreach ($report['detail_rows'] as $row) {
                fputcsv($handle, [
                    $row['vehicle_name'].' · Dia '.$row['payment_day'].' · '.$row['folio'],
                    $row['payment_progress'],
                    Money::decimal($row['payment_cents']),
                    $row['due_date'] ?? '-',
                    $row['late_days'].' dias',
                    Money::decimal($row['overdue_cents']),
                    $row['client_name'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeAccess(Request $request, bool $export = false): void
    {
        $user = $request->user();
        $canView = $user->can('portfolio.view')
            || $user->can('reports.view-all')
            || $user->can('loans.view-assigned');

        abort_unless($canView, 403);

        if ($export) {
            abort_unless($user->can('exports.run') || $user->can('portfolio.export') || $user->can('loans.view-assigned'), 403);
        }

        if ($user->hasRole('operador-cartera') && $request->filled('operator_id')) {
            abort_unless((string) $request->user()->operatorProfile?->id === $request->string('operator_id')->toString(), 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'operator_id' => ['nullable'],
        ]);

        $validated['cutoff_date'] = CarbonImmutable::now('America/Merida')->toDateString();
        $validated['mode'] = 'complete';
        $validated['upcoming_range'] = 'month';
        $validated['per_page'] = (int) $request->integer('per_page', 15);

        return $validated;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page = max((int) $request->integer('page', 1), 1);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    /**
     * @return Collection<int, Operator>
     */
    private function operators(Request $request): Collection
    {
        return Operator::query()
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->whereKey($request->user()->operatorProfile?->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, string $action, array $filters, array $after): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => 'portfolio_balance_report',
            'after' => array_merge($after, [
                'filters' => collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->all(),
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }
}
