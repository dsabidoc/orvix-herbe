<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashCollectionCut extends Model
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['cut_on' => 'date', 'version' => 'integer', 'custody_locked_at' => 'datetime'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CashCutItem::class)->whereNull('removed_at');
    }

    public function pendingLoans(): HasMany
    {
        return $this->hasMany(CashPendingLoan::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CashCutReceipt::class);
    }

    public function offsets(): HasMany
    {
        return $this->hasMany(CashCutOffset::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(CashPendingDisbursement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CashCutReview::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(CashCutCorrection::class);
    }

    public function receiptAdjustments(): HasMany
    {
        return $this->hasMany(CashCutReceiptAdjustment::class);
    }

    public function disbursementCorrections(): HasMany
    {
        return $this->hasMany(CashDisbursementCorrection::class);
    }

    public function offsetCorrections(): HasMany
    {
        return $this->hasMany(CashOffsetCorrection::class);
    }

    public function hasPendingCorrection(): bool
    {
        return $this->corrections()->where('status', 'pending')->exists() || $this->disbursementCorrections()->where('status', 'pending')->exists() || $this->offsetCorrections()->where('status', 'pending')->exists();
    }

    public function statusLabel(): string
    {
        return ['draft' => 'En preparación', 'submitted' => 'Pendiente de Herbe', 'observed' => 'Con observaciones', 'approved_test' => 'Autorizado · Prueba'][$this->status] ?? 'Solo consulta';
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('operator_id', $user->cashOperators()->where('status', 'active')->select('operators.id'));
    }
}
