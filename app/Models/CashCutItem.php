<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCutItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'closes_installment' => 'boolean', 'removed_at' => 'datetime', 'amount_cents' => 'integer'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(CashTestInstallment::class, 'cash_test_installment_id');
    }
}
