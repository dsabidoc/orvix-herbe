<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionMovement extends Model
{
    protected $fillable = [
        'public_id',
        'folio',
        'idempotency_key',
        'loan_id',
        'target_installment_id',
        'operator_id',
        'weekly_cut_id',
        'origin_weekly_cut_id',
        'registered_by',
        'confirmed_by',
        'reversed_movement_id',
        'operated_on',
        'registered_at',
        'confirmed_at',
        'contract_amount',
        'operator_surcharge_amount',
        'external_concepts_amount',
        'additional_charge_amount',
        'delinquency_amount',
        'type',
        'payment_method',
        'reference',
        'notes',
        'confirmation_status',
    ];

    protected function casts(): array
    {
        return [
            'operated_on' => 'date',
            'registered_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function targetInstallment(): BelongsTo
    {
        return $this->belongsTo(Installment::class, 'target_installment_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function weeklyCut(): BelongsTo
    {
        return $this->belongsTo(WeeklyCut::class);
    }

    public function originWeeklyCut(): BelongsTo
    {
        return $this->belongsTo(WeeklyCut::class, 'origin_weekly_cut_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(CollectionMovement::class, 'reversed_movement_id');
    }
}
