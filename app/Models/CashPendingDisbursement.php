<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashPendingDisbursement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'delivered_on' => 'date', 'snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La entrega de capital confirmada no se sobrescribe.'));
        static::deleting(fn () => throw new LogicException('La entrega de capital confirmada no se elimina.'));
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(CashDisbursementCorrection::class);
    }

    public function originalState(): array
    {
        return ['account_id' => $this->cash_account_id, 'delivered_on' => $this->delivered_on->toDateString(),
            'recipient_name' => $this->recipient_name, 'reference' => $this->reference, 'cancelled' => false];
    }

    public function effectiveState(): array
    {
        return $this->corrections()->where('status', 'approved')->latest('id')->first()?->proposal['after'] ?? $this->originalState();
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CashPendingLoan::class, 'cash_pending_loan_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CashOperation::class, 'cash_operation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
