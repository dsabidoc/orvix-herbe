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
        'settlement_on',
        'expected_total',
        'reported_total',
        'received_total',
        'confirmed_total',
        'difference_total',
        'previous_balance',
        'regularization_total',
        'funds_delivered_total',
        'adjustments_in_total',
        'adjustments_out_total',
        'net_result_total',
        'accumulated_balance',
        'status',
        'submitted_at',
        'confirmed_at',
        'balance_settled_at',
        'balance_settled_by',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'settlement_on' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'balance_settled_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
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

    public function fundDisbursements(): HasMany
    {
        return $this->hasMany(FundDisbursement::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(OperatorLedgerEntry::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
