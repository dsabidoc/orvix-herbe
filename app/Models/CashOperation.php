<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashOperation extends Model
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'operated_on' => 'date', 'delivered_on' => 'date', 'received_on' => 'date', 'delivered_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (($record->getOriginal('kind') === 'transfer' && $record->getOriginal('status') === 'accepted') || (in_array($record->getOriginal('kind'), ['income', 'expense', 'manual_adjustment'], true) && $record->getOriginal('status') === 'posted')) {
                throw new LogicException('El movimiento confirmado original no se sobrescribe.');
            }
        });
        static::deleting(function (self $record): void {
            if (($record->kind === 'transfer' && $record->status === 'accepted') || (in_array($record->kind, ['income', 'expense', 'manual_adjustment'], true) && $record->status === 'posted')) {
                throw new LogicException('El movimiento confirmado original no se elimina.');
            }
        });
    }

    public function manualCorrections(): HasMany
    {
        return $this->hasMany(CashManualCorrection::class, 'cash_operation_id');
    }

    public function originalManualState(): array
    {
        return ['amount_cents' => $this->amount_cents, 'operated_on' => $this->operated_on->toDateString(), 'reference' => $this->reason, 'cancelled' => false];
    }

    public function effectiveManualState(): array
    {
        return $this->manualCorrections()->where('status', 'approved')->latest('id')->first()?->proposal['after'] ?? $this->originalManualState();
    }

    public function transferCorrections(): HasMany
    {
        return $this->hasMany(CashTransferCorrection::class, 'cash_operation_id');
    }

    public function originalTransferState(): array
    {
        return ['delivered_on' => $this->delivered_on?->toDateString(), 'received_on' => $this->received_on?->toDateString(),
            'reference' => $this->reason, 'cancelled' => false];
    }

    public function effectiveTransferState(): array
    {
        return $this->transferCorrections()->where('status', 'approved')->latest('id')->first()?->proposal['after'] ?? $this->originalTransferState();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'source_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'destination_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user): void {
            $q->whereHas('source', fn (Builder $a) => $a->visibleTo($user))
                ->orWhere(function (Builder $incoming) use ($user): void {
                    $incoming->whereIn('kind', ['transfer', 'disbursement_correction', 'transfer_reversal'])->where('status', '!=', 'prepared')->where('status', '!=', 'cancelled')
                        ->whereHas('destination', fn (Builder $a) => $a->visibleTo($user));
                });
        });
    }

    public function statusLabel(): string
    {
        if ($this->kind === 'transfer' && $this->status === 'accepted' && $this->effectiveTransferState()['cancelled']) {
            return 'Cancelado con autorización';
        }

        if (in_array($this->kind, ['income', 'expense'], true)) {
            if ($this->effectiveManualState()['cancelled']) {
                return 'Cancelado con autorización';
            }
            if ($this->manualCorrections()->where('status', 'approved')->exists()) {
                return 'Corregido con autorización';
            }
        }

        return ['posted' => 'Registrado', 'prepared' => 'Preparado', 'delivered' => 'Por aceptar', 'accepted' => 'Aceptado', 'disputed' => 'En aclaración', 'cancelled' => 'Cancelado sin entrega'][$this->status];
    }

    public function kindLabel(): string
    {
        return ['manual_adjustment' => 'Ajuste de movimiento manual', 'transfer_reversal' => 'Cancelación de traspaso', 'disbursement_cancel' => 'Cancelación de entrega de capital', 'disbursement_correction' => 'Corrección de caja de capital', 'pending_disbursement' => 'Entrega de capital', 'cut_adjustment' => 'Ajuste de recibo', 'cut_receipt' => 'Recepción de corte', 'income' => 'Ingreso', 'expense' => 'Egreso', 'transfer' => 'Traspaso'][$this->kind];
    }
}
