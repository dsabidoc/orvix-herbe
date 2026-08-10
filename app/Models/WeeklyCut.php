<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyCut extends Model
{
    protected $fillable = [
        'public_id',
        'operator_id',
        'submitted_by',
        'confirmed_by',
        'period_starts_on',
        'period_ends_on',
        'expected_total',
        'reported_total',
        'received_total',
        'difference_total',
        'previous_balance',
        'regularization_total',
        'accumulated_balance',
        'status',
        'submitted_at',
        'confirmed_at',
        'balance_settled_at',
        'balance_settled_by',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'balance_settled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WeeklyCutItem::class);
    }
}
