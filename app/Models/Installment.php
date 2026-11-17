<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Installment extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (Installment $letter) => $letter->public_id ??= (string) Str::ulid());
    }

    protected $fillable = [
        'loan_id',
        'term_version_id',
        'number',
        'due_date',
        'contract_amount',
        'principal_amount',
        'administration_fee_amount',
        'interest_amount',
        'interest_vat_amount',
        'capital_balance',
        'applied_amount',
        'remaining_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function reportedMovement(): HasOne
    {
        return $this->hasOne(CollectionMovement::class, 'target_installment_id')
            ->where('confirmation_status', 'reported')
            ->latestOfMany();
    }

    public function scopeAvailableForQuickPayment(Builder $query): Builder
    {
        return $query->where('remaining_amount', '>', 0)->whereDoesntHave('reportedMovement');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
