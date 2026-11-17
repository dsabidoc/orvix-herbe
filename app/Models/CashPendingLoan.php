<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashPendingLoan extends Model
{
    protected $guarded = ['id'];

    public function cut(): BelongsTo
    {
        return $this->belongsTo(CashCollectionCut::class, 'cash_collection_cut_id');
    }

    public function fundingCancelled(): bool
    {
        return ($this->disbursement()->first()?->effectiveState()['cancelled'] ?? false) || ($this->offset()->first()?->effectiveState()['cancelled'] ?? false);
    }

    public function offset(): HasOne
    {
        return $this->hasOne(CashCutOffset::class);
    }

    public function disbursement(): HasOne
    {
        return $this->hasOne(CashPendingDisbursement::class);
    }

    public function conversion(): HasOne
    {
        return $this->hasOne(CashLoanConversion::class);
    }

    protected function casts(): array
    {
        return ['terms' => 'array', 'schedule' => 'array', 'capital_cents' => 'integer', 'authorized_at' => 'datetime'];
    }
}
