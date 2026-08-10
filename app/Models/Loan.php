<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $fillable = [
        'public_id',
        'folio',
        'client_id',
        'operator_id',
        'vehicle_id',
        'loan_application_id',
        'calculation_method',
        'capital',
        'monthly_rate',
        'administration_fee',
        'administration_fee_type',
        'vat_enabled',
        'interest_calculation_method',
        'rounding_multiple',
        'interest_monthly',
        'interest_total',
        'collection_total',
        'first_payment_amount',
        'regular_payment_amount',
        'quote_snapshot',
        'quoted_by',
        'quoted_at',
        'confirmed_by',
        'confirmed_at',
        'term_months',
        'contract_total',
        'start_date',
        'payment_day',
        'status',
        'settlement_reason',
        'settled_at',
        'settled_by',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'settled_at' => 'datetime',
            'vat_enabled' => 'boolean',
            'quote_snapshot' => 'array',
            'quoted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CollectionMovement::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }
}
