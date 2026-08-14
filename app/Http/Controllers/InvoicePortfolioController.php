<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class InvoicePortfolioController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorizeAccess($request);

        $filters = $request->validate([
            'holder' => ['nullable', 'in:caja,recepcion,operador,sin_ubicacion'],
        ]);

        $query = $this->query($request, $filters)
            ->orderByRaw('COALESCE(loans.first_payment_date, loans.start_date) asc')
            ->orderBy('loans.folio');

        return view('invoice-portfolio.index', [
            'rows' => (clone $query)->paginate(25)->withQueryString(),
            'printRows' => $query->get(),
            'filters' => $filters,
            'holderOptions' => $this->holderOptions(),
        ]);
    }

    private function query(Request $request, array $filters): Builder
    {
        $query = Loan::query()
            ->with(['client', 'operator', 'vehicle', 'invoiceDocument'])
            ->where('status', 'active');

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        }

        match ($filters['holder'] ?? null) {
            'caja' => $query->where('invoice_holder', 'like', '%Caja%'),
            'recepcion' => $query->where(function (Builder $query) {
                $query->where('invoice_holder', 'like', '%Recepcion%')
                    ->orWhere('invoice_holder', 'like', '%Recepción%');
            }),
            'operador' => $query->where('invoice_holder', 'like', '%Operador%'),
            'sin_ubicacion' => $query->where(fn (Builder $query) => $query->whereNull('invoice_holder')->orWhere('invoice_holder', '')),
            default => null,
        };

        return $query;
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->can('portfolio.view')
                || $user->can('reports.view-all')
                || $user->can('loans.view-assigned'),
            403
        );
    }

    /**
     * @return array<string, string>
     */
    private function holderOptions(): array
    {
        return [
            '' => 'Todas las ubicaciones',
            'caja' => 'Caja',
            'recepcion' => 'Recepcion',
            'operador' => 'Operador',
            'sin_ubicacion' => 'Sin ubicacion',
        ];
    }
}
