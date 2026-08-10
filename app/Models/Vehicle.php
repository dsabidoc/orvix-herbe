<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'public_id',
        'client_id',
        'brand',
        'model',
        'year',
        'color',
        'vin',
        'plates',
        'engine_number',
        'price',
        'gps_status',
        'invoice_data',
        'circulation_card',
        'tenure_data',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'invoice_data' => 'array',
            'circulation_card' => 'array',
            'tenure_data' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
