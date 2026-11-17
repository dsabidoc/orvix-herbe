<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAccount extends Model
{
    protected $fillable = ['public_id', 'name', 'kind', 'currency', 'loans_enabled', 'naboo_enabled', 'active'];

    protected function casts(): array
    {
        return ['loans_enabled' => 'boolean', 'naboo_enabled' => 'boolean', 'active' => 'boolean', 'is_test' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        // Administrators also need an explicit assignment for operational views.
        $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'cash_transfer_routes', 'source_id', 'destination_id');
    }

    public function balanceCents(): int
    {
        return (int) $this->entries()->sum('amount_cents');
    }

    public function allows(User $user, string $action): bool
    {
        return $this->users()->whereKey($user->id)->wherePivot('can_'.$action, true)->exists();
    }
}
