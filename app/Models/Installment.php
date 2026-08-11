<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Installment extends Model
{
    protected $fillable = [
        'loan_id',
        'term_version_id',
        'number',
        'due_date',
        'contract_amount',
        'principal_amount',
        'administration_fee_amount',
        'interest_amount',
        'interest_vat_amount',
        'capital_balance',
        'applied_amount',
        'remaining_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function reportedMovement(): HasOne
    {
        return $this->hasOne(CollectionMovement::class, 'target_installment_id')
            ->whereIn('confirmation_status', ['reported', 'applied'])
            ->latestOfMany();
    }
}
