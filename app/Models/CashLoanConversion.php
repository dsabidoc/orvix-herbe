<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashLoanConversion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'cut_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if ((bool) $record->cash_cut_offset_id === (bool) $record->cash_pending_disbursement_id) {
                throw new LogicException('La conversión debe reutilizar exactamente una fuente de capital.');
            }
        });
        static::updating(fn () => throw new LogicException('La conversión confirmada no se sobrescribe.'));
        static::deleting(fn () => throw new LogicException('La conversión confirmada no se elimina.'));
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CashTestLoan::class, 'cash_test_loan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
