<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'initial_capital',
        'available_capital',
        'returned_capital_balance',
        'generated_interest_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'initial_capital' => 'decimal:2',
            'available_capital' => 'decimal:2',
            'returned_capital_balance' => 'decimal:2',
            'generated_interest_balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function capitalMovements(): HasMany
    {
        return $this->hasMany(InvestorCapitalMovement::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(InvestorWithdrawalRequest::class);
    }
}
