<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashCutCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_version' => 'integer', 'target_id' => 'integer', 'input' => 'array', 'proposal' => 'array', 'baseline' => 'array', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las solicitudes no se eliminan.'));
        static::updating(function (self $record): void {
            if ($record->getOriginal('status') !== 'pending' || array_diff(array_keys($record->getDirty()), ['status', 'active_cut_id', 'resolved_by', 'resolution_note', 'resolved_at', 'updated_at'])) {
                throw new LogicException('La propuesta y su resolución se conservan.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function cut(): BelongsTo
    {
        return $this->belongsTo(CashCollectionCut::class, 'cash_collection_cut_id');
    }

    public function statusLabel(): string
    {
        return ['pending' => 'Pendiente de Herbe', 'approved' => 'Corrección aplicada · Prueba', 'rejected' => 'Rechazada'][$this->status];
    }
}
