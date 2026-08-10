<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Loan;
use App\Models\Operator;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $query = Client::query()
            ->with('operator')
            ->withCount(['loans', 'loans as active_loans_count' => fn ($query) => $query->where('status', 'active')]);

        if ($request->user()->hasRole('operador-cartera')) {
            $query->where('operator_id', $request->user()->operatorProfile?->id);
        }

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($query) => $query
                ->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('phone', 'like', $search)
                ->orWhere('email', 'like', $search));
        }

        return view('clients.index', [
            'clients' => $query->latest()->paginate(15)->withQueryString(),
            'kpis' => $this->indexKpis($request),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('clients.create') || $request->user()->can('clients.manage'), 403);

        return view('clients.create', [
            'operators' => Operator::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('clients.create') || $request->user()->can('clients.manage'), 403);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'operator_id' => ['nullable', 'exists:operators,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->user()->hasRole('operador-cartera')) {
            $data['operator_id'] = $request->user()->operatorProfile?->id;
        }

        $client = Client::query()->create($data + [
            'public_id' => (string) Str::ulid(),
            'status' => 'active',
        ]);

        return redirect()->route('clients.show', $client)->with('status', 'Cliente creado.');
    }

    public function show(Request $request, Client $client): View
    {
        if ($request->user()->hasRole('operador-cartera') && $client->operator_id !== $request->user()->operatorProfile?->id) {
            abort(403);
        }

        $client->load(['operator', 'loans.installments', 'loans.vehicle']);

        return view('clients.show', [
            'client' => $client,
            'summary' => $this->summary($client),
            'score' => $this->score($client),
        ]);
    }

    private function summary(Client $client): array
    {
        $loans = $client->loans;

        return [
            'capital' => $loans->sum(fn ($loan) => Money::cents($loan->capital)),
            'contract' => $loans->sum(fn ($loan) => Money::cents($loan->contract_total)),
            'applied' => $loans->sum(fn ($loan) => $loan->installments->sum(fn ($installment) => Money::cents($installment->applied_amount))),
            'balance' => $loans->sum(fn ($loan) => $loan->installments->sum(fn ($installment) => Money::cents($installment->remaining_amount))),
        ];
    }

    private function score(Client $client): array
    {
        $loans = $client->loans;

        if ($loans->count() < 2) {
            return ['label' => 'Sin historial suficiente', 'score' => null, 'note' => 'Aplica cuando el cliente tiene mas de un credito.'];
        }

        $installments = $loans->flatMap->installments;
        $total = max(1, $installments->count());
        $paid = $installments->filter(fn ($installment) => Money::cents($installment->remaining_amount) === 0)->count();
        $overdue = $installments->filter(fn ($installment) => $installment->due_date->isPast() && Money::cents($installment->remaining_amount) > 0)->count();
        $badSettlements = $loans->where('settlement_reason', 'dejo_de_pagar')->count();
        $earlySettlements = $loans->where('settlement_reason', 'pronto_pago_cliente')->count();

        $score = (int) max(0, min(100, 45 + (($paid / $total) * 40) - (($overdue / $total) * 35) + ($earlySettlements * 8) - ($badSettlements * 25)));
        $label = $score >= 80 ? 'Buen cliente' : ($score >= 60 ? 'Cliente estable' : 'Cliente de riesgo');

        return ['label' => $label, 'score' => $score, 'note' => 'Formula: cumplimiento de letras, atrasos vivos y motivos de liquidacion historicos.'];
    }

    private function indexKpis(Request $request): array
    {
        $clientScope = Client::query()
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id));
        $activeLoans = Loan::query()
            ->where('status', 'active')
            ->when($request->user()->hasRole('operador-cartera'), fn ($query) => $query->where('operator_id', $request->user()->operatorProfile?->id));

        return [
            ['title' => 'Clientes', 'value' => number_format((clone $clientScope)->count()), 'caption' => 'Total en cartera visible', 'color' => 'blue'],
            ['title' => 'Clientes con prestamos', 'value' => number_format((clone $clientScope)->has('loans')->count()), 'caption' => 'Con al menos un credito', 'color' => 'green'],
            ['title' => 'Prestamos activos', 'value' => number_format($activeLoans->count()), 'caption' => 'Creditos vivos', 'color' => 'orange'],
        ];
    }
}
