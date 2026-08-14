<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Operator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class InvoicePortfolioController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorizeAccess($request);

        $filters = $request->validate([
            'holder' => ['nullable', 'in:caja,recepcion,operador,sin_ubicacion'],
            'operator_id' => ['nullable'],
        ]);

        $query = $this->query($request, $filters)
            ->orderBy('loans.payment_day')
            ->orderByRaw('COALESCE(loans.first_payment_date, loans.start_date) asc')
            ->orderBy('loans.folio');

        return view('invoice-portfolio.index', [
            'rows' => (clone $query)->paginate(25)->withQueryString(),
            'printRows' => $query->get(),
            'filters' => $filters,
            'holderOptions' => $this->holderOptions(),
            'operators' => $this->operators($request),
        ]);
    }

    private function query(Request $request, array $filters): Builder
    {
        $query = Loan::query()
            ->with(['client', 'operator', 'vehicle', 'invoiceDocument'])
            ->where('status', 'active');

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        } elseif (($filters['operator_id'] ?? null) === 'none') {
            $query->whereNull('operator_id');
        } elseif (! empty($filters['operator_id'])) {
            $query->where('operator_id', (int) $filters['operator_id']);
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

        if ($user->hasRole('operador-cartera') && $request->filled('operator_id')) {
            abort_unless((string) $user->operatorProfile?->id === $request->string('operator_id')->toString(), 403);
        }
    }

    /**
     * @return Collection<int, Operator>
     */
    private function operators(Request $request): Collection
    {
        if ($request->user()->hasRole('operador-cartera')) {
            return collect([$request->user()->operatorProfile])->filter()->values();
        }

        return Operator::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
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
