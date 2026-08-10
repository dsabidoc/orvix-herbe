<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'name',
        'email',
        'phone',
        'status',
    ];

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }
}
