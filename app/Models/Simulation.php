<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Simulation extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'client_id',
        'capital',
        'monthly_rate',
        'administration_fee',
        'administration_fee_type',
        'vat_enabled',
        'rate_type',
        'interest_calculation_method',
        'term_months',
        'start_date',
        'payment_day',
        'rounding_increment',
        'rounding_adjustment',
        'opening_fee_type',
        'opening_fee_value',
        'opening_fee_amount',
        'total_interest',
        'contract_total',
        'schedule',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'schedule' => 'array',
            'vat_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
