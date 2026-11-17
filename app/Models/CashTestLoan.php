<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashTestLoan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['terms' => 'array', 'schedule' => 'array', 'active' => 'boolean', 'collectible' => 'boolean'];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CashTestInstallment::class);
    }

    public function sourceLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function paymentLabel(): string
    {
        $source = $this->loan_id ? $this->sourceLoan : null;
        $model = trim((string) ($source?->vehicle?->model ?? $this->terms['vehicle_model'] ?? ''));
        $day = (int) ($source?->payment_day ?? $this->terms['payment_day'] ?? 0);
        $model = $model !== '' ? $model : ($source ? 'Vehículo' : 'Sin modelo');

        return $day >= 1 && $day <= 31 ? "$model · Día $day" : $model;
    }
}
