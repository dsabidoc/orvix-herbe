<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operator extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'name',
        'phone',
        'email',
        'cut_day',
        'tolerance_days',
        'max_overdue_installments',
        'allows_shortfalls',
        'assumes_collection_risk',
        'covers_installment_when_client_misses',
        'alert_rules',
        'status',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'allows_shortfalls' => 'boolean',
            'assumes_collection_risk' => 'boolean',
            'covers_installment_when_client_misses' => 'boolean',
            'alert_rules' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
