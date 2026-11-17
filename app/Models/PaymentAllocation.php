<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'collection_movement_id',
        'installment_id',
        'amount',
        'principal_cents',
        'interest_waived_cents',
    ];

    protected $casts = ['principal_cents' => 'integer', 'interest_waived_cents' => 'integer'];

    protected $hidden = ['principal_cents', 'interest_waived_cents'];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CollectionMovement::class, 'collection_movement_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
