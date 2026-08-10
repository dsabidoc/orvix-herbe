<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplication extends Model
{
    protected $fillable = [
        'public_id',
        'folio',
        'client_id',
        'vehicle_id',
        'operator_id',
        'simulation_id',
        'vehicle_price',
        'down_payment',
        'requested_capital',
        'monthly_rate',
        'administration_fee',
        'administration_fee_type',
        'vat_enabled',
        'term_months',
        'payment_day',
        'status',
        'notes',
        'approved_by',
        'approved_at',
        'approved_conditions',
        'rejected_reason',
        'started_at',
        'loan_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'approved_conditions' => 'array',
            'started_at' => 'datetime',
            'vat_enabled' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
