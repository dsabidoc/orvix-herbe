<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashReconciliation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expected_cents' => 'integer', 'counted_cents' => 'integer', 'difference_cents' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            $allowed = ['status', 'active_account_id', 'resolved_by', 'resolved_at', 'resolution_note', 'resolution_key', 'resolution_fingerprint', 'updated_at'];
            if ($record->getOriginal('status') !== 'pending' || ! in_array($record->status, ['closed', 'rejected', 'withdrawn'], true)
                || array_diff(array_keys($record->getDirty()), $allowed)) {
                throw new LogicException('El arqueo original y los cierres se conservan sin sobrescribir.');
            }
        });
        static::deleting(fn () => throw new LogicException('Los arqueos y cierres no se eliminan.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function differenceResolutions(): HasMany
    {
        return $this->hasMany(CashDifferenceResolution::class, 'cash_reconciliation_id');
    }

    public function previousClose(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_close_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'closed' => $this->difference_cents === 0 ? 'Cerrado · Cuadrado' : 'Cerrado con diferencia',
            'rejected' => 'Rechazado · Repetir conteo',
            'withdrawn' => 'Retirado · Repetir conteo',
            default => 'Pendiente de cierre',
        };
    }
}
