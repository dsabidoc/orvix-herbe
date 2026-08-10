<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Investment extends Model
{
    protected $fillable = [
        'public_id',
        'investor_id',
        'loan_id',
        'vehicle_id',
        'amount',
        'investor_share_rate',
        'administrator_share_rate',
        'starts_on',
        'ends_on',
        'status',
        'agreement_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'agreement_snapshot' => 'array',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
