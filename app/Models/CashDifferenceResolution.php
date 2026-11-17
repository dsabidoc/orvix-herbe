<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashDifferenceResolution extends Model
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['input' => 'array', 'proposal' => 'array', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getOriginal('status') !== 'pending' || ! in_array($record->status, ['approved', 'rejected'], true)
                || array_diff(array_keys($record->getDirty()), ['status', 'active_reconciliation_id', 'resolved_by', 'resolved_at', 'resolution_note', 'updated_at'])) {
                throw new LogicException('La aclaración original y su resolución se conservan.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las aclaraciones no se eliminan.'));
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CashReconciliation::class, 'cash_reconciliation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function statusLabel(): string
    {
        return ['pending' => 'Pendiente de Herbe', 'approved' => 'Aclaración autorizada', 'rejected' => 'Aclaración rechazada'][$this->status];
    }
}
