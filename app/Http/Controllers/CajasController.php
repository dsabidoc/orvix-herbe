<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\CajasSandbox;
use App\Models\AuditEvent;
use App\Models\CashAccount;
use App\Models\CashCollectionCut;
use App\Models\CashCutCorrection;
use App\Models\CashOperation;
use App\Models\CashReconciliation;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CajasController extends Controller
{
    public function home(Request $request): View
    {
        return view('cajas.home', [
            'pendingCorrections' => $this->pendingCorrections($request->user()),
            'pendingCuts' => CajasSandbox::enabled() && $request->user()->can('cajas.collections.loans.view') && $request->user()->can('cajas.cuts.review') && $request->user()->can('cajas.pending-loans.view')
                ? CashCollectionCut::visibleTo($request->user())->where('status', 'submitted')->with('operator')->orderBy('cut_on')->get() : collect(),
            'pendingTransfers' => $request->user()->can('cajas.accounts.view')
                ? CashOperation::where('kind', 'transfer')->whereIn('status', ['delivered', 'disputed'])
                    ->whereHas('destination', fn ($q) => $q->visibleTo($request->user()))->with(['source', 'destination'])->orderBy('id')->get() : collect(),
            'accounts' => $request->user()->can('cajas.accounts.view')
                ? CashAccount::visibleTo($request->user())->where('active', true)->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function accounts(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->can('cajas.accounts.view'), 403);

        $accounts = CashAccount::visibleTo($request->user())->orderBy('name')->get();
        if ($accounts->count() === 1) {
            return redirect()->route('cajas.accounts.show', $accounts->first());
        }

        return view('cajas.accounts', compact('accounts'));
    }

    public function account(Request $request, string $account): View
    {
        abort_unless($request->user()->can('cajas.accounts.view'), 403);

        $record = CashAccount::visibleTo($request->user())->where('public_id', $account)->firstOrFail();

        return view('cajas.account', [
            'account' => $record,
            'hasOtherAccounts' => CashAccount::visibleTo($request->user())->whereKeyNot($record->id)->exists(),
            'reconciliations' => CashReconciliation::where('cash_account_id', $record->id)->with(['creator', 'resolver'])->orderByDesc('id')->paginate(10, ['*'], 'arqueos'),
            'entries' => $record->entries()->with('operation')->orderByDesc('id')->paginate(20),
            'transfers' => CashOperation::visibleTo($request->user())->where('kind', 'transfer')
                ->whereIn('status', ['prepared', 'delivered', 'disputed'])->where(fn ($q) => $q->where('source_id', $record->id)->orWhere('destination_id', $record->id))
                ->with(['source', 'destination'])->orderByDesc('id')->get(),
            'outgoingCents' => CashOperation::where('source_id', $record->id)->whereIn('status', ['delivered', 'disputed'])->sum('amount_cents'),
            'incomingCents' => CashOperation::where('destination_id', $record->id)->whereIn('status', ['delivered', 'disputed'])->sum('amount_cents'),
        ]);
    }

    public function collections(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('cajas.collections.loans.view') || $user->can('cajas.collections.naboo.view'), 403);

        $reviewFilter = $request->query('review') === 'pending';
        if ($reviewFilter) {
            abort_unless($user->can('cajas.cuts.review') && $user->can('cajas.pending-loans.view'), 403);
        }

        return view('cajas.collections', [
            'reviewFilter' => $reviewFilter,
            'pendingCorrections' => $this->pendingCorrections($user),
            'cuts' => CajasSandbox::enabled() && $user->can('cajas.collections.loans.view')
                ? CashCollectionCut::visibleTo($user)->when($reviewFilter, fn ($q) => $q->where('status', 'submitted'))->with('operator')->withSum('items', 'amount_cents')->orderByDesc('cut_on')->orderByDesc('id')->paginate(15)->withQueryString() : null,
        ]);
    }

    private function pendingCorrections(User $user): Collection
    {
        return CajasSandbox::enabled() && $user->can('cajas.corrections.resolve') && $user->can('cajas.pending-loans.view') && $user->can('cajas.collections.loans.view')
            ? CashCutCorrection::where('status', 'pending')->whereHas('cut', fn ($q) => $q->visibleTo($user))->with('cut.operator')->orderBy('id')->get() : collect();
    }

    public function settings(Request $request): View
    {
        $user = $request->user();
        $canViewOperators = $user->can('cajas.collections.loans.view');
        abort_unless($canViewOperators || $user->can('cajas.accounts.manage') || $user->can('cajas.access.manage'), 403);

        return view('cajas.settings', [
            'operators' => $canViewOperators ? $user->cashOperators()->orderBy('name')->get(['operators.id', 'operators.name', 'operators.status']) : collect(),
            'hasLoanAccount' => $canViewOperators && $user->can('cajas.accounts.view') && CashAccount::visibleTo($user)
                ->where('active', true)->where('loans_enabled', true)->exists(),
            // Metadata catalog only. These grants never imply access to balances/history.
            'accounts' => $user->can('cajas.accounts.manage') ? CashAccount::orderBy('name')->get() : collect(),
            'users' => $user->can('cajas.access.manage') ? User::permission('cajas.access')->orderBy('name')->get(['id', 'name', 'status']) : collect(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('cajas.accounts.manage'), 403);
        $data = $this->accountData($request);
        DB::transaction(function () use ($request, $data): void {
            $account = CashAccount::create(['public_id' => (string) Str::ulid(), ...$data]);
            $account->forceFill(['is_test' => CajasSandbox::enabled()])->save();
            $this->audit($request, 'cajas.account.created', $account, [], $account->only(array_keys($data)));
        });

        return redirect()->route('cajas.settings')->with('status', 'Caja creada. Asigna sus accesos para que aparezca en Cajas. No se registró dinero.');
    }

    public function updateAccount(Request $request, string $account): RedirectResponse
    {
        abort_unless($request->user()->can('cajas.accounts.manage'), 403);
        $data = $this->accountData($request);
        DB::transaction(function () use ($request, $data, $account): void {
            $record = CashAccount::where('public_id', $account)->lockForUpdate()->firstOrFail();
            if (! $data['active'] && CashReconciliation::where('active_account_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['active' => 'Resuelve el arqueo pendiente antes de desactivar la caja.']);
            }
            if (($record->kind !== $data['kind'] || $record->currency !== $data['currency']) && CashReconciliation::where('cash_account_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['kind' => 'Una caja con arqueos conserva su tipo y moneda.']);
            }
            if (! $data['active'] && CashOperation::whereIn('status', ['prepared', 'delivered', 'disputed'])
                ->where(fn ($q) => $q->where('source_id', $record->id)->orWhere('destination_id', $record->id))->exists()) {
                throw ValidationException::withMessages(['active' => 'Resuelve los traspasos pendientes antes de desactivar la caja.']);
            }
            if (($record->kind !== $data['kind'] || $record->currency !== $data['currency'])
                && CashOperation::where(fn ($q) => $q->where('source_id', $record->id)->orWhere('destination_id', $record->id))->exists()) {
                throw ValidationException::withMessages(['kind' => 'Una caja con operaciones conserva su tipo y moneda.']);
            }
            $before = $record->only(array_keys($data));
            $record->update($data);
            $this->audit($request, 'cajas.account.updated', $record, $before, $record->only(array_keys($data)));
        });

        return redirect()->route('cajas.settings')->with('status', 'Configuración de caja actualizada.');
    }

    public function access(Request $request, User $user): View
    {
        abort_unless($request->user()->can('cajas.access.manage'), 403);
        abort_unless($user->can('cajas.access'), 404);

        return view('cajas.access', [
            'member' => $user,
            'accounts' => CashAccount::orderBy('name')->get(),
            'operators' => Operator::orderBy('name')->get(['id', 'name', 'status']),
            'accountIds' => $user->cashAccounts()->pluck('cash_accounts.id')->all(),
            'operatorIds' => $user->cashOperators()->pluck('operators.id')->all(),
            'cashScopes' => DB::table('cash_account_user')->where('user_id', $user->id)->get()->keyBy('cash_account_id'),
        ]);
    }

    public function updateAccess(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('cajas.access.manage'), 403);
        abort_unless($user->can('cajas.access'), 404);
        $data = $request->validate([
            'account_ids' => ['sometimes', 'array'],
            'account_ids.*' => ['integer', 'distinct', Rule::exists('cash_accounts', 'id')],
            'operator_ids' => ['sometimes', 'array'],
            'operator_ids.*' => ['integer', 'distinct', Rule::exists('operators', 'id')],
            'reason' => ['required', 'string', 'max:500'],
            ...collect(['income', 'expense', 'send', 'receive', 'adjust', 'count', 'close'])->flatMap(fn ($action) => [
                $action.'_account_ids' => ['sometimes', 'array'],
                $action.'_account_ids.*' => ['integer', 'distinct', Rule::in($request->input('account_ids', []))],
            ])->all(),
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = ['account_ids' => $user->cashAccounts()->pluck('cash_accounts.id')->all(), 'operator_ids' => $user->cashOperators()->pluck('operators.id')->all(),
                'cash_scopes' => DB::table('cash_account_user')->where('user_id', $user->id)->get()->map(fn ($row) => (array) $row)->all()];
            $after = ['account_ids' => $data['account_ids'] ?? [], 'operator_ids' => $data['operator_ids'] ?? []];
            $scopes = [];
            foreach ($after['account_ids'] as $id) {
                foreach (['income', 'expense', 'send', 'receive', 'adjust', 'count', 'close'] as $action) {
                    $scopes[$id]['can_'.$action] = in_array($id, $data[$action.'_account_ids'] ?? []);
                }
            }
            $after['cash_scopes'] = $scopes;
            $user->cashAccounts()->sync($scopes);
            $user->cashOperators()->sync($after['operator_ids']);
            $this->audit($request, 'cajas.access.updated', $user, $before, $after);
        });

        return redirect()->route('cajas.access', $user)->with('status', 'Alcances actualizados. Cada acción requiere también el permiso correspondiente del perfil.');
    }

    public function updateDestinations(Request $request, string $account): RedirectResponse
    {
        abort_unless($request->user()->can('cajas.access.manage') && $request->user()->can('cajas.accounts.manage'), 403);
        $data = $request->validate(['destination_ids' => ['sometimes', 'array'], 'destination_ids.*' => ['integer', 'distinct', 'exists:cash_accounts,id']]);
        DB::transaction(function () use ($request, $account, $data): void {
            $record = CashAccount::where('public_id', $account)->lockForUpdate()->firstOrFail();
            $ids = $data['destination_ids'] ?? [];
            if (in_array($record->id, $ids) || CashAccount::whereIn('id', $ids)->where(fn ($q) => $q->where('currency', '!=', $record->currency)->orWhere('is_test', '!=', $record->is_test))->exists()) {
                throw ValidationException::withMessages(['destination_ids' => 'Elige otras cajas de la misma moneda y del mismo entorno de prueba.']);
            }
            $before = ['destination_ids' => $record->destinations()->pluck('cash_accounts.id')->all()];
            $record->destinations()->sync($ids);
            $this->audit($request, 'cajas.destinations.updated', $record, $before, ['destination_ids' => $ids]);
        });

        return redirect()->route('cajas.settings')->with('status', 'Destinos permitidos actualizados. Esto no concede acceso a sus saldos.');
    }

    private function accountData(Request $request): array
    {
        $canManageSources = $request->user()->can('cajas.access.manage');
        abort_if(! $canManageSources && ($request->has('loans_enabled') || $request->has('naboo_enabled')), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['cash', 'bank'])],
            'currency' => ['required', Rule::in(['MXN'])],
            'loans_enabled' => ['sometimes', 'boolean'],
            'naboo_enabled' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($canManageSources) {
            $data['loans_enabled'] = $request->boolean('loans_enabled');
            $data['naboo_enabled'] = $request->boolean('naboo_enabled');
        }

        return [...$data, 'active' => $request->boolean('active')];
    }

    private function audit(Request $request, string $action, CashAccount|User $resource, array $before, array $after): void
    {
        AuditEvent::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'auditable_type' => $resource->getMorphClass(), 'auditable_id' => $resource->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            'before' => $before, 'after' => $after, 'reason' => $request->input('reason'),
        ]);
    }
}
