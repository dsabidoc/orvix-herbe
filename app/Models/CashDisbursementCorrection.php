<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashDisbursementCorrection extends Model
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['input' => 'array', 'proposal' => 'array', 'base_version' => 'integer', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getOriginal('status') !== 'pending' || array_diff(array_keys($record->getDirty()),
                ['status', 'active_cut_id', 'resolved_by', 'resolved_at', 'resolution_note', 'cash_operation_id', 'updated_at'])) {
                throw new LogicException('La propuesta y su resolución se conservan.');
            }
        });
        static::deleting(fn () => throw new LogicException('El historial no se elimina.'));
    }

    public function cut(): BelongsTo
    {
        return $this->belongsTo(CashCollectionCut::class, 'cash_collection_cut_id');
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(CashPendingDisbursement::class, 'cash_pending_disbursement_id');
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
        return ['pending' => 'Pendiente de Herbe', 'approved' => 'Autorizada', 'rejected' => 'Rechazada'][$this->status];
    }
}
