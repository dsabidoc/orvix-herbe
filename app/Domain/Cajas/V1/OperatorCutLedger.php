<?php

namespace App\Domain\Cajas\V1;

use App\Models\Operator;
use App\Models\OperatorLedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

class OperatorCutLedger
{
    public function __construct(private IntegrationAccess $access) {}

    public function linkCutDisbursement(Operator $operator, User $actor, array $cut, array $delivery): void
    {
        $reference = $delivery['disbursement_id'];
        $credit = $delivery['pending_credit_id'];
        $toOperator = $delivery['recipient']['type'] === 'operator';
        $original = $this->disbursementEntry($reference);
        if ($original) {
            if (! $toOperator || $original->operator_id !== $operator->id
                || Money::cents($original->amount) !== $delivery['amount_cents']
                || ! str_ends_with($original->reason ?? '', ' · '.$credit)) {
                $this->access->fail(409, 'FUNDING_CONFLICT', 'El asiento previo no coincide con el desembolso del crédito.');
            }

            return;
        }
        if ($toOperator) {
            $this->record($operator, $actor, $cut, 'cajas_v1_disbursement', $reference, $delivery['amount_cents'], $credit);
        }
    }

    public function reverseDisbursement(Operator $operator, User $actor, array $cut, string $reference, string $reconciliation, int $amount, string $credit): void
    {
        $original = $this->disbursementEntry($reference);
        if (! $original || $original->operator_id !== $operator->id || Money::cents($original->amount) !== $amount) {
            $this->access->fail(409, 'FUNDING_CONFLICT', 'El cargo original del desembolso no coincide con el fondeo bloqueado.');
        }

        $this->record($operator, $actor, $cut, 'cajas_v1_disbursement_reversal', $reconciliation, -$amount, $credit);
    }

    private function disbursementEntry(string $reference): ?OperatorLedgerEntry
    {
        return OperatorLedgerEntry::where('idempotency_key', 'cajas_v1_disbursement:'.hash('sha256', $reference))->first();
    }

    public function record(Operator $operator, User $actor, array $cut, string $type, string $source, int $amount, ?string $relatedId = null): void
    {
        $origin = $type === 'cajas_v1_payment' ? $cut['cut_id'].':'.$source : $source;
        $key = $type.':'.hash('sha256', $origin);
        if (OperatorLedgerEntry::where('idempotency_key', $key)->exists()) {
            $this->access->fail(409, 'CUT_REFERENCE_ALREADY_APPLIED', 'Una referencia ya se aplicó en otro corte.');
        }
        // The native ledger uses the inverse sign for the operator's balance.
        $before = Money::cents(OperatorLedgerEntry::where('operator_id', $operator->id)->latest('id')->value('balance_after'));
        OperatorLedgerEntry::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $operator->id, 'created_by' => $actor->id,
            'type' => $type, 'amount' => Money::decimal($amount), 'balance_before' => Money::decimal($before),
            'balance_after' => Money::decimal($before - $amount), 'idempotency_key' => $key,
            'reason' => $cut['cut_id'].' v'.$cut['version'].' · '.$source.($relatedId ? ' · '.$relatedId : ''),
        ]);
    }
}
