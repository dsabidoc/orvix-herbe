<?php

namespace App\Http\Controllers;

use App\Domain\Investors\InvestmentAllocationService;
use App\Models\Investor;
use App\Models\InvestorCapitalMovement;
use App\Models\InvestorWithdrawalRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class InvestorController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage')) {
            $investor = $request->user()->investorProfile;

            abort_unless($investor, 403);

            return redirect()->route('investors.show', $investor);
        }

        abort_unless($request->user()->can('investors.manage'), 403);

        $query = Investor::query()->withCount(['investments', 'withdrawalRequests'])->orderBy('name');

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($query) => $query
                ->where('name', 'like', $search)
                ->orWhere('email', 'like', $search)
                ->orWhere('phone', 'like', $search));
        }

        return view('investors.index', [
            'investors' => $query->paginate(15)->withQueryString(),
            'investorUsers' => User::query()
                ->role('inversionista')
                ->whereDoesntHave('investorProfile')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone']),
        ]);
    }

    public function store(Request $request, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);

        $data = $request->validate([
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('status', 'active')),
                Rule::unique('investors', 'user_id'),
            ],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'initial_capital' => ['nullable', 'numeric', 'min:0'],
            'create_user' => ['nullable', 'boolean'],
            'email' => [
                ($request->boolean('create_user') || $request->filled('user_id')) ? 'required' : 'nullable',
                'email',
                'max:160',
                'unique:investors,email',
                Rule::unique('users', 'email')->ignore($request->input('user_id')),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);

        $investor = DB::transaction(function () use ($request, $data, $ledger) {
            $user = null;

            if (! empty($data['user_id'])) {
                $user = User::query()->role('inversionista')->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();

                if ($user->investorProfile()->exists()) {
                    abort(422, 'Este usuario ya esta ligado a un inversionista.');
                }
            } elseif ($request->boolean('create_user')) {
                $password = $data['password'] ?: 'orvix-demo';
                $user = User::query()->create([
                    'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => $password,
                    'status' => 'active',
                    'force_password_change' => true,
                ]);
                Role::findOrCreate('inversionista', 'web');
                $user->assignRole('inversionista');
            }

            $initialCapital = number_format((float) ($data['initial_capital'] ?? 0), 2, '.', '');
            $investor = Investor::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user?->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'initial_capital' => $initialCapital,
                'available_capital' => '0.00',
                'status' => 'active',
            ]);

            $ledger->creditAvailable($investor, Money::cents($initialCapital), 'initial_capital', $request->user()->id, notes: 'Capital inicial');

            return $investor;
        });

        return redirect()->route('investors.show', $investor)->with('status', 'Inversionista creado.');
    }

    public function show(Request $request, Investor $investor): View
    {
        if ($request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage')) {
            abort_unless($request->user()->investorProfile?->id === $investor->id, 403);
        } else {
            abort_unless($request->user()->can('investors.manage'), 403);
        }

        return view('investors.show', [
            'investor' => $investor->load([
                'user',
                'investments.loan.client',
                'investments.loan.installments',
                'investments.loan.vehicle',
                'capitalMovements' => fn ($query) => $query->latest()->limit(30),
                'withdrawalRequests' => fn ($query) => $query->latest()->limit(20),
            ]),
            'canManage' => $request->user()->can('investors.manage'),
        ]);
    }

    public function requestWithdrawal(Request $request, Investor $investor): RedirectResponse
    {
        abort_unless(
            $request->user()->can('investor-withdrawals.request') && $request->user()->investorProfile?->id === $investor->id,
            403
        );

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (Money::cents($data['amount']) > Money::cents($investor->available_capital)) {
            return back()->withErrors(['amount' => 'El monto solicitado excede tu capital disponible.'])->withInput();
        }

        InvestorWithdrawalRequest::query()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investor->id,
            'requested_by' => $request->user()->id,
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Solicitud de retiro enviada.');
    }

    public function processWithdrawal(Request $request, InvestorWithdrawalRequest $withdrawal, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investor-withdrawals.manage'), 403);

        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $withdrawal, $data, $ledger) {
            $withdrawal = InvestorWithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status !== 'submitted') {
                return;
            }

            if ($data['action'] === 'approve') {
                $ledger->debitAvailable(
                    $withdrawal->investor()->lockForUpdate()->firstOrFail(),
                    Money::cents($withdrawal->amount),
                    'withdrawal_paid',
                    $request->user()->id,
                    notes: $data['admin_notes'] ?? 'Retiro aprobado',
                );
            }

            $withdrawal->update([
                'status' => $data['action'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now('America/Merida'),
                'admin_notes' => $data['admin_notes'] ?? null,
            ]);
        });

        return back()->with('status', 'Solicitud de retiro actualizada.');
    }

    public function directWithdrawal(Request $request, Investor $investor, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investor-withdrawals.manage'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ledger->debitAvailable(
            $investor,
            Money::cents($data['amount']),
            'admin_capital_withdrawal',
            $request->user()->id,
            notes: $data['notes'] ?? 'Retiro directo registrado por administracion',
        );

        return back()->with('status', 'Retiro directo registrado.');
    }

    public function creditReturns(Request $request, Investor $investor): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);

        $data = $request->validate([
            'returned_capital' => ['nullable', 'numeric', 'min:0'],
            'generated_interest' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $returnedCapitalCents = Money::cents($data['returned_capital'] ?? 0);
        $generatedInterestCents = Money::cents($data['generated_interest'] ?? 0);

        if ($returnedCapitalCents + $generatedInterestCents <= 0) {
            return back()->withErrors(['returned_capital' => 'Captura capital retornado o interes generado.'])->withInput();
        }

        $investor->forceFill([
            'returned_capital_balance' => Money::decimal(Money::cents($investor->returned_capital_balance) + $returnedCapitalCents),
            'generated_interest_balance' => Money::decimal(Money::cents($investor->generated_interest_balance) + $generatedInterestCents),
        ])->save();

        InvestorCapitalMovement::query()->create([
            'public_id' => (string) Str::ulid(),
            'investor_id' => $investor->id,
            'created_by' => $request->user()->id,
            'type' => 'returns_recorded',
            'amount' => Money::decimal($returnedCapitalCents + $generatedInterestCents),
            'balance_before' => $investor->available_capital,
            'balance_after' => $investor->available_capital,
            'notes' => $data['notes'] ?? 'Retornos registrados',
            'metadata' => [
                'returned_capital' => Money::decimal($returnedCapitalCents),
                'generated_interest' => Money::decimal($generatedInterestCents),
            ],
        ]);

        return back()->with('status', 'Retornos registrados.');
    }

    public function reinvest(Request $request, Investor $investor, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);

        $data = $request->validate([
            'include_returned_capital' => ['nullable', 'boolean'],
            'include_generated_interest' => ['nullable', 'boolean'],
        ]);

        $returnedCapitalCents = $request->boolean('include_returned_capital') ? Money::cents($investor->returned_capital_balance) : 0;
        $generatedInterestCents = $request->boolean('include_generated_interest') ? Money::cents($investor->generated_interest_balance) : 0;
        $amountCents = $returnedCapitalCents + $generatedInterestCents;

        if ($amountCents <= 0) {
            return back()->withErrors(['reinvest' => 'Selecciona capital retornado o interes generado con saldo.']);
        }

        DB::transaction(function () use ($request, $investor, $ledger, $returnedCapitalCents, $generatedInterestCents, $amountCents) {
            $investor = Investor::query()->whereKey($investor->id)->lockForUpdate()->firstOrFail();
            $investor->forceFill([
                'returned_capital_balance' => Money::decimal(Money::cents($investor->returned_capital_balance) - $returnedCapitalCents),
                'generated_interest_balance' => Money::decimal(Money::cents($investor->generated_interest_balance) - $generatedInterestCents),
            ])->save();

            $ledger->creditAvailable($investor, $amountCents, 'returns_reinvested', $request->user()->id, notes: 'Retornos convertidos a capital disponible');
        });

        return back()->with('status', 'Retornos reinvertidos a capital disponible.');
    }
}
