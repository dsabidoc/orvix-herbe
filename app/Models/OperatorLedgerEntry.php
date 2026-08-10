<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorLedgerEntry extends Model
{
    protected $fillable = [
        'public_id',
        'operator_id',
        'weekly_cut_id',
        'created_by',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'idempotency_key',
        'reason',
        'settled_at',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
