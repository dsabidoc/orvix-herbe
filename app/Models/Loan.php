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
        'first_payment_date',
        'payment_day',
        'guarantor_name',
        'guarantor_address',
        'guarantor_phone',
        'is_frozen',
        'frozen_reason',
        'frozen_at',
        'frozen_by',
        'delinquency_rate',
        'delinquency_grace_days',
        'invoice_holder',
        'invoice_document_id',
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
            'first_payment_date' => 'date',
            'settled_at' => 'datetime',
            'is_frozen' => 'boolean',
            'frozen_at' => 'datetime',
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

    public function invoiceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_document_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LoanNote::class);
    }

    public function invoiceMovements(): HasMany
    {
        return $this->hasMany(LoanInvoiceMovement::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function fundDisbursements(): HasMany
    {
        return $this->hasMany(FundDisbursement::class);
    }
}
