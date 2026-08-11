<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundDisbursement extends Model
{
    protected $fillable = [
        'public_id',
        'loan_id',
        'operator_id',
        'weekly_cut_id',
        'client_id',
        'vehicle_id',
        'investor_id',
        'registered_by',
        'amount',
        'delivered_on',
        'registered_at',
        'capital_source',
        'notes',
        'evidence_path',
        'status',
        'is_delivery_date_inferred',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'registered_at' => 'datetime',
            'is_delivery_date_inferred' => 'boolean',
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

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function weeklyCut(): BelongsTo
    {
        return $this->belongsTo(WeeklyCut::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
