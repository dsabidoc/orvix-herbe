<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashCutReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'received_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El registro confirmado no se sobrescribe.'));
        static::deleting(fn () => throw new LogicException('El registro confirmado no se elimina.'));
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CashCutReceiptAdjustment::class);
    }

    public function effectiveCents(): int
    {
        return $this->amount_cents + (int) $this->adjustments()->sum('amount_cents');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CashOperation::class, 'cash_operation_id');
    }
}
