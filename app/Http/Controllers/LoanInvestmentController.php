<?php

namespace App\Http\Controllers;

use App\Domain\Investors\InvestmentAllocationService;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoanInvestmentController extends Controller
{
    public function store(Request $request, Loan $loan, InvestmentAllocationService $allocator): RedirectResponse
    {
        abort_unless($request->user()->can('investors.manage') || $request->user()->can('loans.formalize'), 403);

        $data = $request->validate([
            'investors' => ['nullable', 'array', 'max:8'],
            'investors.*.investor_id' => ['nullable', 'exists:investors,id'],
            'investors.*.capital_amount' => ['nullable', 'numeric', 'min:0'],
            'investors.*.interest_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $allocator->assignFromInput($loan, $data['investors'] ?? [], $request->user()->id);

        return redirect()->route('loans.show', $loan)->with('status', 'Inversionistas actualizados para este prestamo.');
    }
}
