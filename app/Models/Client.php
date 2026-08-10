<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'public_id',
        'operator_id',
        'first_name',
        'last_name',
        'phone',
        'alternate_phone',
        'email',
        'address',
        'curp',
        'rfc',
        'identification_type',
        'identification_last4',
        'manual_score',
        'calculated_score',
        'status',
        'notes',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'manual_score' => 'integer',
            'calculated_score' => 'integer',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
