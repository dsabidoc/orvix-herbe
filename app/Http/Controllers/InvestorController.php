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
use Illuminate\Validation\ValidationException;
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

        $query = Investor::query()
            ->where('status', '!=', 'deleted')
            ->withCount(['investments', 'withdrawalRequests'])
            ->orderBy('name');

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
                ->whereDoesntHave('investorProfile', fn ($query) => $query->where('status', '!=', 'deleted'))
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
                Rule::unique('investors', 'user_id')->where(fn ($query) => $query->where('status', '!=', 'deleted')),
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
                Rule::unique('investors', 'email')->where(fn ($query) => $query->where('status', '!=', 'deleted')),
                Rule::unique('users', 'email')->ignore($request->input('user_id')),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);

        $investor = DB::transaction(function () use ($request, $data, $ledger) {
            $user = null;

            if (! empty($data['user_id'])) {
                $user = User::query()
                    ->where('status', 'active')
                    ->whereKey($data['user_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingProfile = $user->investorProfile()->lockForUpdate()->first();

                if ($existingProfile && $existingProfile->status !== 'deleted') {
                    abort(422, 'Este usuario ya esta ligado a un inversionista.');
                }

                if ($existingProfile?->status === 'deleted') {
                    $initialCapital = number_format((float) ($data['initial_capital'] ?? 0), 2, '.', '');
                    $existingProfile->forceFill([
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'] ?? '',
                        'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                        'email' => $data['email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'initial_capital' => $initialCapital,
                        'available_capital' => '0.00',
                        'returned_capital_balance' => '0.00',
                        'generated_interest_balance' => '0.00',
                        'status' => 'active',
                    ])->save();

                    $ledger->creditAvailable($existingProfile, Money::cents($initialCapital), 'initial_capital', $request->user()->id, notes: 'Capital inicial al reactivar inversionista');

                    return $existingProfile;
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

    public function update(Request $request, Investor $investor, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);
        abort_if($investor->status === 'deleted', 404);

        $data = $request->validate([
            'initial_capital' => ['required', 'numeric', 'min:0'],
            'available_capital' => ['required', 'numeric'],
        ]);

        DB::transaction(function () use ($request, $investor, $data, $ledger) {
            $investor = Investor::query()->whereKey($investor->id)->lockForUpdate()->firstOrFail();

            $newInitialCents = Money::cents($data['initial_capital']);
            $oldAvailableCents = Money::cents($investor->available_capital);
            $newAvailableCents = Money::cents($data['available_capital']);
            $availableDeltaCents = $newAvailableCents - $oldAvailableCents;

            $investor->forceFill([
                'initial_capital' => Money::decimal($newInitialCents),
            ])->save();

            if ($availableDeltaCents > 0) {
                $ledger->creditAvailable($investor, $availableDeltaCents, 'available_capital_adjusted', $request->user()->id, notes: 'Ajuste manual de capital disponible');
            } elseif ($availableDeltaCents < 0) {
                $ledger->debitAvailable($investor, abs($availableDeltaCents), 'available_capital_adjusted', $request->user()->id, notes: 'Ajuste manual de capital disponible');
            }
        });

        return back()->with('status', 'Capital del inversionista actualizado.');
    }

    public function destroy(Request $request, Investor $investor): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);

        $hasLiveLoan = $investor->investments()
            ->where('status', 'active')
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->exists();

        if ($hasLiveLoan) {
            return back()->withErrors([
                'investor' => 'No se puede eliminar este inversionista porque tiene prestamos activos vivos.',
            ]);
        }

        $investor->forceFill([
            'status' => 'deleted',
        ])->save();

        return redirect()->route('investors.index')->with('status', 'Inversionista eliminado.');
    }

    public function show(Request $request, Investor $investor): View
    {
        abort_if($investor->status === 'deleted', 404);

        if ($request->user()->can('investments.view-own') && ! $request->user()->can('investors.manage')) {
            abort_unless($request->user()->investorProfile?->id === $investor->id, 403);
        } else {
            abort_unless($request->user()->can('investors.manage'), 403);
        }

        return view('investors.show', [
            'investor' => $investor->load([
                'user',
                'investments' => fn ($query) => $query
                    ->with(['loan.client', 'loan.installments', 'loan.vehicle'])
                    ->leftJoin('loans', 'investments.loan_id', '=', 'loans.id')
                    ->select('investments.*')
                    ->orderByRaw('COALESCE(loans.payment_day, 99)')
                    ->orderBy('loans.folio'),
                'capitalMovements' => fn ($query) => $query->with('createdBy')->latest(),
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

    public function creditAvailableCapital(Request $request, Investor $investor, InvestmentAllocationService $ledger): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);
        abort_if($investor->status === 'deleted', 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ledger->creditAvailable(
            $investor,
            Money::cents($data['amount']),
            'available_capital_contribution',
            $request->user()->id,
            notes: $data['notes'] ?? 'Aporte directo a capital disponible',
        );

        return back()->with('status', 'Capital disponible agregado.');
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
            'returned_capital_amount' => ['nullable', 'numeric', 'min:0'],
            'generated_interest_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $availableReturnedCapitalCents = Money::cents($investor->returned_capital_balance);
        $availableGeneratedInterestCents = Money::cents($investor->generated_interest_balance);
        $requestedReturnedCapitalCents = Money::cents($data['returned_capital_amount'] ?? 0);
        $requestedGeneratedInterestCents = Money::cents($data['generated_interest_amount'] ?? 0);

        $useReturnedCapital = $request->boolean('include_returned_capital') || $requestedReturnedCapitalCents > 0;
        $useGeneratedInterest = $request->boolean('include_generated_interest') || $requestedGeneratedInterestCents > 0;

        $returnedCapitalCents = $useReturnedCapital
            ? ($requestedReturnedCapitalCents > 0 ? $requestedReturnedCapitalCents : $availableReturnedCapitalCents)
            : 0;
        $generatedInterestCents = $useGeneratedInterest
            ? ($requestedGeneratedInterestCents > 0 ? $requestedGeneratedInterestCents : $availableGeneratedInterestCents)
            : 0;
        $amountCents = $returnedCapitalCents + $generatedInterestCents;

        if ($amountCents <= 0) {
            return back()->withErrors(['reinvest' => 'Selecciona capital retornado o interes generado con saldo.'])->withInput();
        }

        if ($returnedCapitalCents > $availableReturnedCapitalCents) {
            return back()->withErrors(['returned_capital_amount' => 'El monto no puede ser mayor al capital retornado disponible.'])->withInput();
        }

        if ($generatedInterestCents > $availableGeneratedInterestCents) {
            return back()->withErrors(['generated_interest_amount' => 'El monto no puede ser mayor al interes generado disponible.'])->withInput();
        }

        DB::transaction(function () use ($request, $investor, $ledger, $returnedCapitalCents, $generatedInterestCents, $amountCents) {
            $investor = Investor::query()->whereKey($investor->id)->lockForUpdate()->firstOrFail();
            $availableReturnedCapitalCents = Money::cents($investor->returned_capital_balance);
            $availableGeneratedInterestCents = Money::cents($investor->generated_interest_balance);

            if ($returnedCapitalCents > $availableReturnedCapitalCents || $generatedInterestCents > $availableGeneratedInterestCents) {
                throw ValidationException::withMessages([
                    'reinvest' => 'Los saldos disponibles cambiaron. Revisa los importes e intenta de nuevo.',
                ]);
            }

            $investor->forceFill([
                'returned_capital_balance' => Money::decimal($availableReturnedCapitalCents - $returnedCapitalCents),
                'generated_interest_balance' => Money::decimal($availableGeneratedInterestCents - $generatedInterestCents),
            ])->save();

            if ($returnedCapitalCents > 0) {
                $ledger->creditAvailable(
                    $investor,
                    $returnedCapitalCents,
                    'reinvest_capital',
                    $request->user()->id,
                    notes: 'Capital retornado convertido a capital disponible.',
                );
            }

            if ($generatedInterestCents > 0) {
                $ledger->creditAvailable(
                    $investor,
                    $generatedInterestCents,
                    'reinvest_interest',
                    $request->user()->id,
                    notes: 'Interes generado convertido a capital disponible.',
                );
            }
        });

        return back()->with('status', 'Retornos reinvertidos a capital disponible.');
    }

    public function cancelCapitalMovement(Request $request, Investor $investor, InvestorCapitalMovement $movement): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage'), 403);
        abort_if($investor->status === 'deleted', 404);
        abort_unless($movement->investor_id === $investor->id, 404);

        $allowedTypes = [
            'available_capital_contribution',
            'available_capital_adjusted',
            'admin_capital_withdrawal',
            'withdrawal_paid',
            'withdrawal',
            'reinvest_capital',
            'reinvest_interest',
            'returns_reinvested',
        ];

        if (str_starts_with((string) $movement->type, 'cancel_') || ! in_array($movement->type, $allowedTypes, true)) {
            return back()->withErrors(['movement' => 'Este movimiento no se puede cancelar desde esta pantalla.']);
        }

        if ($this->capitalMovementCancellationExists($movement)) {
            return back()->withErrors(['movement' => 'Este movimiento ya fue cancelado previamente.']);
        }

        DB::transaction(function () use ($request, $investor, $movement) {
            $investor = Investor::query()->whereKey($investor->id)->lockForUpdate()->firstOrFail();
            $movement = InvestorCapitalMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($this->capitalMovementCancellationExists($movement)) {
                throw ValidationException::withMessages([
                    'movement' => 'Este movimiento ya fue cancelado previamente.',
                ]);
            }

            $availableBeforeCents = Money::cents($investor->available_capital);
            $returnedBeforeCents = Money::cents($investor->returned_capital_balance);
            $interestBeforeCents = Money::cents($investor->generated_interest_balance);
            $movementDeltaCents = Money::cents($movement->balance_after) - Money::cents($movement->balance_before);
            $availableAfterCents = $availableBeforeCents - $movementDeltaCents;
            $returnedAfterCents = $returnedBeforeCents;
            $interestAfterCents = $interestBeforeCents;

            if ($movement->type === 'reinvest_capital') {
                $returnedAfterCents += abs(Money::cents($movement->amount));
            }

            if ($movement->type === 'reinvest_interest') {
                $interestAfterCents += abs(Money::cents($movement->amount));
            }

            if ($movement->type === 'returns_reinvested') {
                $returnedFromMetadataCents = Money::cents($movement->metadata['returned_capital'] ?? 0);
                $interestFromMetadataCents = Money::cents($movement->metadata['generated_interest'] ?? 0);

                if ($returnedFromMetadataCents + $interestFromMetadataCents <= 0) {
                    $returnedFromMetadataCents = abs(Money::cents($movement->amount));
                }

                $returnedAfterCents += $returnedFromMetadataCents;
                $interestAfterCents += $interestFromMetadataCents;
            }

            $investor->forceFill([
                'available_capital' => Money::decimal($availableAfterCents),
                'returned_capital_balance' => Money::decimal($returnedAfterCents),
                'generated_interest_balance' => Money::decimal($interestAfterCents),
            ])->save();

            InvestorCapitalMovement::query()->create([
                'public_id' => (string) Str::ulid(),
                'investor_id' => $investor->id,
                'loan_id' => $movement->loan_id,
                'investment_id' => $movement->investment_id,
                'created_by' => $request->user()->id,
                'type' => 'cancel_'.$movement->type,
                'amount' => Money::decimal(-Money::cents($movement->amount)),
                'balance_before' => Money::decimal($availableBeforeCents),
                'balance_after' => Money::decimal($availableAfterCents),
                'notes' => 'CANCELA_MOVIMIENTO:'.$movement->id.' Cancelacion de movimiento de capital.',
                'metadata' => [
                    'cancels_movement_id' => $movement->id,
                    'original_type' => $movement->type,
                    'available_before' => Money::decimal($availableBeforeCents),
                    'available_after' => Money::decimal($availableAfterCents),
                    'returned_capital_before' => Money::decimal($returnedBeforeCents),
                    'returned_capital_after' => Money::decimal($returnedAfterCents),
                    'generated_interest_before' => Money::decimal($interestBeforeCents),
                    'generated_interest_after' => Money::decimal($interestAfterCents),
                ],
            ]);
        });

        return back()->with('status', 'Movimiento de capital cancelado.');
    }

    private function capitalMovementCancellationExists(InvestorCapitalMovement $movement): bool
    {
        return InvestorCapitalMovement::query()
            ->where('investor_id', $movement->investor_id)
            ->where('type', 'like', 'cancel_%')
            ->where('notes', 'like', '%CANCELA_MOVIMIENTO:'.$movement->id.'%')
            ->exists();
    }
}
