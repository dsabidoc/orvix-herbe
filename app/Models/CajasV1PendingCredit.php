<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CajasV1PendingCredit extends Model
{
    protected $table = 'cajas_v1_pending_credits';

    protected $fillable = [
        'public_id', 'cut_id', 'cut_version', 'operator_id', 'offset_id', 'offset',
        'disbursement_id', 'disbursement', 'funding_hold', 'funding_cancelled_version',
        'proposal', 'observations', 'reviewed_schedule', 'status', 'loan_id',
    ];

    protected $casts = [
        'offset' => 'array', 'disbursement' => 'array', 'funding_hold' => 'array', 'proposal' => 'array', 'observations' => 'array',
        'reviewed_schedule' => 'array', 'cut_version' => 'integer', 'funding_cancelled_version' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function funding(): array
    {
        if ($this->funding_cancelled_version !== null) {
            return ['type' => 'unfunded', 'status' => 'unfunded', 'version' => $this->funding_cancelled_version];
        }

        $confirmed = $this->funding_hold === null
            ? ['status' => 'confirmed', 'version' => ($this->disbursement['expected_funding_version'] ?? 0) + 1]
            : ['status' => 'under_reconciliation', 'version' => $this->funding_hold['expected_funding_version'] + 1];
        if ($this->disbursement_id !== null) {
            return [
                'type' => 'disbursement', ...$confirmed,
                'reference_id' => $this->disbursement_id, 'amount_cents' => $this->disbursement['amount_cents'],
            ];
        }
        if ($this->offset_id !== null) {
            return ['type' => 'offset', ...$confirmed, 'reference_id' => $this->offset_id];
        }

        return ['type' => 'unfunded', 'status' => 'unfunded', 'version' => 0];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
