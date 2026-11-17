<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashCutReceiptAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'operated_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los ajustes no se sobrescriben.'));
        static::deleting(fn () => throw new LogicException('Los ajustes no se eliminan.'));
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CashCutReceipt::class, 'cash_cut_receipt_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CashOperation::class, 'cash_operation_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(CashCutCorrection::class, 'cash_cut_correction_id');
    }
}
