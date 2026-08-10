<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyCutItem extends Model
{
    protected $fillable = [
        'weekly_cut_id',
        'collection_movement_id',
        'expected_amount',
        'reported_amount',
        'received_amount',
        'status',
    ];

    public function weeklyCut(): BelongsTo
    {
        return $this->belongsTo(WeeklyCut::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CollectionMovement::class, 'collection_movement_id');
    }
}
