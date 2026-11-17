<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashTestInstallment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'principal_cents' => 'integer', 'interest_cents' => 'integer'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CashTestLoan::class, 'cash_test_loan_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CashCutItem::class)->whereNull('removed_at');
    }
}
