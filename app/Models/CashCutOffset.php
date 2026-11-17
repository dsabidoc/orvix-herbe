<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashCutOffset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'executed_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El registro confirmado no se sobrescribe.'));
        static::deleting(fn () => throw new LogicException('El registro confirmado no se elimina.'));
    }

    public function cut(): BelongsTo
    {
        return $this->belongsTo(CashCollectionCut::class, 'cash_collection_cut_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(CashOffsetCorrection::class);
    }

    public function originalState(): array
    {
        return ['executed_on' => $this->executed_on->toDateString(), 'reference' => $this->reference, 'cancelled' => false];
    }

    public function effectiveState(): array
    {
        return $this->corrections()->where('status', 'approved')->latest('id')->first()?->proposal['after'] ?? $this->originalState();
    }

    public function effectiveCents(): int
    {
        return $this->effectiveState()['cancelled'] ? 0 : $this->amount_cents;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pendingLoan(): BelongsTo
    {
        return $this->belongsTo(CashPendingLoan::class, 'cash_pending_loan_id');
    }
}
