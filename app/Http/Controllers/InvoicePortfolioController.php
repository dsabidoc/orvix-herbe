<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Operator;
use App\Support\InvoiceHolders;
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
            'holder' => ['nullable', 'string', 'max:80'],
            'invoice_status' => ['nullable', 'in:con_factura,sin_factura'],
            'plates_status' => ['nullable', 'in:na'],
            'investor_status' => ['nullable', 'in:sin_inversionista'],
            'vin_status' => ['nullable', 'in:na'],
            'operator_id' => ['nullable'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $this->query($request, $filters)
            ->orderBy('loans.payment_day')
            ->orderByRaw('COALESCE(loans.first_payment_date, loans.start_date) asc')
            ->orderBy('loans.folio');

        return view('invoice-portfolio.index', [
            'rows' => (clone $query)->paginate(25)->withQueryString(),
            'printRows' => $query->get(),
            'filters' => $filters,
            'holderOptions' => InvoiceHolders::options(includeAll: true),
            'invoiceStatusOptions' => $this->invoiceStatusOptions(),
            'binaryStatusOptions' => $this->binaryStatusOptions(),
            'operators' => $this->operators($request),
        ]);
    }

    private function query(Request $request, array $filters): Builder
    {
        $query = Loan::query()
            ->with(['client', 'operator', 'vehicle', 'invoiceDocument', 'investments.investor'])
            ->where('status', 'active');

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        } elseif (($filters['operator_id'] ?? null) === 'none') {
            $query->whereNull('operator_id');
        } elseif (! empty($filters['operator_id'])) {
            $query->where('operator_id', (int) $filters['operator_id']);
        }

        if (filled($filters['holder'] ?? null)) {
            $holderValues = InvoiceHolders::filterValues($filters['holder']);
            $query->where(function (Builder $query) use ($holderValues): void {
                $nonBlankValues = array_values(array_filter($holderValues, fn ($value) => $value !== ''));

                if ($nonBlankValues !== []) {
                    $query->whereIn('invoice_holder', $nonBlankValues);
                }

                if (in_array('', $holderValues, true)) {
                    $query->orWhereNull('invoice_holder')->orWhere('invoice_holder', '');
                }
            });
        }

        match ($filters['invoice_status'] ?? null) {
            'con_factura' => $query->whereNotNull('invoice_document_id'),
            'sin_factura' => $query->whereNull('invoice_document_id'),
            default => null,
        };

        if (($filters['plates_status'] ?? null) === 'na') {
            $query->whereHas('vehicle', fn (Builder $query) => $this->whereBlankOrNa($query, 'plates'));
        }

        if (($filters['vin_status'] ?? null) === 'na') {
            $query->whereHas('vehicle', fn (Builder $query) => $this->whereBlankOrNa($query, 'vin'));
        }

        if (($filters['investor_status'] ?? null) === 'sin_inversionista') {
            $query->whereDoesntHave('investments');
        }

        if (filled($filters['q'] ?? null)) {
            $search = '%'.str($filters['q'])->trim()->toString().'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('folio', 'like', $search)
                    ->orWhereHas('client', fn (Builder $query) => $query
                        ->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('phone', 'like', $search))
                    ->orWhereHas('vehicle', fn (Builder $query) => $query
                        ->where('brand', 'like', $search)
                        ->orWhere('model', 'like', $search)
                        ->orWhere('plates', 'like', $search)
                        ->orWhere('vin', 'like', $search));
            });
        }

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

    private function whereBlankOrNa(Builder $query, string $column): Builder
    {
        return $query->whereNull($column)
            ->orWhere($column, '')
            ->orWhereRaw('UPPER('.$column.') IN (?, ?)', ['NA', 'N/A']);
    }

    /**
     * @return array<string, string>
     */
    private function invoiceStatusOptions(): array
    {
        return [
            '' => 'Todos',
            'con_factura' => 'Con factura cargada',
            'sin_factura' => 'Sin factura cargada',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function binaryStatusOptions(): array
    {
        return [
            '' => 'Todos',
            'na' => 'N/A',
        ];
    }
}
