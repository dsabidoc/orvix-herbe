<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'operated_on' => 'date', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los asientos no se sobrescriben.'));
        static::deleting(fn () => throw new LogicException('Los asientos no se eliminan.'));
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CashOperation::class, 'cash_operation_id');
    }
}
