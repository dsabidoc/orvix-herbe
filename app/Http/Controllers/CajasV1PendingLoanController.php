<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\V1\PendingCredits;
use App\Domain\Cajas\V1\IntegrationAccess;
use App\Domain\Loans\LoanFormalizer;
use App\Models\CajasV1PendingCredit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CajasV1PendingLoanController extends Controller
{
    public function __construct(private IntegrationAccess $access, private PendingCredits $credits) {}

    public function index(Request $request): View
    {
        $proposals = $this->visible($request)->with('operator', 'loan')->latest('id')->paginate(25);

        return view('loans.cajas-pending', compact('proposals'));
    }

    public function show(Request $request, string $pendingCredit): View
    {
        $credit = $this->find($request, $pendingCredit);
        $calculated = $credit->status === 'requires_review' ? $this->credits->schedule($credit->proposal['terms']) : [];

        return view('loans.cajas-pending-show', [
            'credit' => $credit, 'calculated' => $calculated,
            'scheduleHash' => $this->credits->scheduleHash($calculated),
        ]);
    }

    public function review(Request $request, string $pendingCredit): RedirectResponse
    {
        $credit = $this->find($request, $pendingCredit);
        $this->access->requireAdmission();
        $data = $request->validate(['schedule_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/']]);
        $this->credits->reviewSchedule($credit, $request->user(), $data['schedule_token']);

        return redirect()->route('loans.cajas-pending.show', $pendingCredit)->with('status', 'Calendario revisado. Ya puedes autorizar el alta.');
    }

    public function store(Request $request, string $pendingCredit, LoanFormalizer $formalizer): RedirectResponse
    {
        $credit = $this->find($request, $pendingCredit);
        $this->access->requireAdmission();
        $loan = $this->credits->formalize($credit, $request->user(), $formalizer);
        abort_unless($loan, 409, 'SCHEDULE_MISMATCH: El calendario cambió. Revisa la propuesta.');

        return redirect()->route('loans.show', $loan)->with('status', 'Crédito formalizado con el fondeo confirmado en Cajas.');
    }

    private function find(Request $request, string $id): CajasV1PendingCredit
    {
        return $this->visible($request)->with('operator', 'loan')->where('public_id', $id)->firstOrFail();
    }

    private function visible(Request $request): Builder
    {
        abort_unless($this->access->availableTo($request), 404);
        $user = $request->user();
        abort_unless($user->can('loans.formalize')
            && (! $user->can('investments.view-own') || $user->can('investors.manage')), 403);

        return CajasV1PendingCredit::whereHas('operator', fn ($operator) => $operator
            ->whereIn('public_id', $this->access->operators()))
            ->when($user->hasRole('operador-cartera'), fn ($query) => $query
                ->where('operator_id', $user->operatorProfile?->id));
    }
}
