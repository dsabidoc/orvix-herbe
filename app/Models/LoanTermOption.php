<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanTermOption extends Model
{
    protected $fillable = [
        'term_months',
        'is_active',
        'created_by',
        'disabled_by',
        'disabled_at',
    ];

    protected $casts = [
        'term_months' => 'integer',
        'is_active' => 'boolean',
        'disabled_at' => 'datetime',
    ];
}
