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
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CollectionMovement::class, 'collection_movement_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
