<?php

namespace App\Domain\Cajas;

use App\Models\CashCutReceipt;
use App\Models\CashCutReceiptAdjustment;
use App\Models\CashDisbursementCorrection;
use App\Models\CashEntry;
use App\Models\CashManualCorrection;
use App\Models\CashOperation;
use App\Models\CashPendingDisbursement;
use App\Models\CashReconciliation;
use App\Models\CashTransferCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Read-only adapter: corrections retain ownership of their money and approvals.
class CashDifferenceEvidence
{
    public function candidates(User $user, CashReconciliation $count): array
    {
        $keys = [];
        $operations = CashEntry::where('cash_account_id', $count->cash_account_id)->where('id', '>', $count->snapshot['last_entry_id'])->with('operation')->get();
        foreach ($operations as $entry) {
            $id = $entry->cash_operation_id;
            $key = match ($entry->operation->kind) {
                'manual_adjustment' => ($r = CashManualCorrection::where('adjustment_operation_id', $id)->where('status', 'approved')->first()) ? 'manual:'.$r->cash_operation_id : null,
                'cut_adjustment' => ($r = CashCutReceiptAdjustment::where('cash_operation_id', $id)->first()) ? 'receipt:'.$r->cash_cut_receipt_id : null,
                'disbursement_correction', 'disbursement_cancel' => ($r = CashDisbursementCorrection::where('cash_operation_id', $id)->where('status', 'approved')->first()) ? 'capital:'.$r->cash_pending_disbursement_id : null,
                'transfer_reversal' => ($r = CashTransferCorrection::where('reversal_operation_id', $id)->where('status', 'approved')->first()) ? 'transfer:'.$r->cash_operation_id : null,
                default => null,
            };
            if ($key) {
                $keys[$key] = true;
            }
        }
        $groups = [];
        foreach (array_keys($keys) as $key) {
            try {
                $group = $this->group($count, $key);
                if (CashOperation::visibleTo($user)->whereKey($group['original_id'])->exists() && $group['amount_cents'] !== 0) {
                    $groups[] = $group;
                }
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [404, 409], true)) {
                    throw $e;
                }
            }
        }

        return $groups;
    }

    public function group(CashReconciliation $count, string $key): array
    {
        abort_unless(preg_match('/^(manual|receipt|capital|transfer):([1-9][0-9]*)$/', $key, $parts), 422, 'Referencia de corrección inválida.');
        [$type, $id] = [$parts[1], (int) $parts[2]];
        if ($type === 'manual') {
            $original = CashOperation::find($id);
            abort_unless($original, 409, 'El original ya no está disponible.');
            app(CashManualCorrectionService::class)->validateHistory($original);
            $records = $original->manualCorrections()->where('status', 'approved')->orderBy('id')->get();
            $ids = $records->pluck('adjustment_operation_id')->filter()->all();
        } elseif ($type === 'transfer') {
            $original = CashOperation::find($id);
            abort_unless($original, 409);
            app(CashTransferCorrectionService::class)->validateHistory($original);
            $records = $original->transferCorrections()->where('status', 'approved')->orderBy('id')->get();
            $ids = $records->pluck('reversal_operation_id')->filter()->all();
        } elseif ($type === 'capital') {
            $delivery = CashPendingDisbursement::find($id);
            abort_unless($delivery && $delivery->operation, 409);
            $original = $delivery->operation;
            app(CashDisbursementCorrectionService::class)->validateHistory($delivery);
            $records = $delivery->corrections()->where('status', 'approved')->orderBy('id')->get();
            $ids = $records->pluck('cash_operation_id')->filter()->all();
        } else {
            $receipt = CashCutReceipt::find($id);
            abort_unless($receipt && $receipt->operation, 409);
            $original = $receipt->operation;
            $records = $receipt->adjustments()->with('correction')->orderBy('id')->get();
            foreach ($records as $adjustment) {
                $correction = $adjustment->correction;
                $rows = CashEntry::where('cash_operation_id', $adjustment->cash_operation_id)->get();
                abort_unless($correction && $correction->status === 'approved' && $correction->kind === 'receipt' && $correction->target_id === $receipt->id
                    && $correction->proposal['cash_delta_cents'] === $adjustment->amount_cents && $correction->resolved_by === $adjustment->created_by
                    && $adjustment->operation->kind === 'cut_adjustment' && $adjustment->operation->status === 'posted'
                    && $rows->count() === 1 && $rows[0]->cash_account_id === $receipt->cash_account_id && $rows[0]->amount_cents === $adjustment->amount_cents
                    && $rows[0]->operated_on->equalTo($adjustment->operated_on), 409, 'El ajuste de recibo no es consistente.');
            }
            $ids = $records->pluck('cash_operation_id')->all();
        }
        $originalEntries = CashEntry::where('cash_operation_id', $original->id)->orderBy('id')->get();
        // Original must already be represented in this box's immutable count. A capital
        // reclassification into another box needs a separate, explicit business decision.
        abort_unless($originalEntries->contains(fn ($e) => $e->cash_account_id === $count->cash_account_id && $e->id <= $count->snapshot['last_entry_id']), 409, 'El movimiento original no formaba parte de esta revisión de saldo.');
        $entries = CashEntry::whereIn('cash_operation_id', $ids)->where('cash_account_id', $count->cash_account_id)
            ->where('id', '>', $count->snapshot['last_entry_id'])->orderBy('id')->get();
        abort_unless($entries->isNotEmpty(), 409, 'No hay ajustes posteriores a esta revisión de saldo.');
        abort_if(DB::table('cash_difference_claims')->whereIn('cash_entry_id', $entries->pluck('id'))->where('cash_reconciliation_id', '!=', $count->id)->exists(), 409, 'Estos ajustes ya se vincularon a otra revisión de saldo.');

        return ['key' => $key, 'original_id' => $original->id, 'original_public_id' => $original->public_id,
            'label' => $original->kindLabel().' · '.$original->public_id, 'amount_cents' => (int) $entries->sum('amount_cents'),
            'entries' => $entries->map(fn ($e) => ['id' => $e->id, 'operation_id' => $e->cash_operation_id, 'amount_cents' => $e->amount_cents])->all(),
            'version' => hash('sha256', json_encode([$original->withoutRelations()->toArray(), $originalEntries->toArray(), $records->toArray(), $entries->toArray()]))];
    }
}
