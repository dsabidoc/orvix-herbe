<?php

namespace App\Domain\Investors;

use App\Models\Investor;
use App\Support\Money;

class ReturnProvenance
{
    public const COMPONENTS = [
        'returned_capital' => 'reinvest_capital',
        'generated_interest' => 'reinvest_interest',
    ];

    public function balance(Investor $investor, string $component): int
    {
        return Money::cents($investor->{$component.'_balance'});
    }

    public function plan(Investor $investor, string $component, int $amount, array $state): array
    {
        $eligible = array_filter($state[$component], fn (array $entry) => ! $entry['uncertain'] && $entry['remaining'] > 0);
        $traceable = array_sum(array_column($eligible, 'remaining'));
        $balance = $this->balance($investor, $component);
        if ($traceable > $balance) {
            return ['return_consumption' => ['component' => $component, 'unattributed_cents' => $amount, 'allocations' => [], 'verified' => false]];
        }

        // Pre-existing balances without an attributable origin remain a separate balance.
        $unattributed = min($amount, $balance - $traceable);
        $remaining = $amount - $unattributed;
        $allocations = [];
        foreach ($eligible as $originId => $origin) {
            if ($remaining === 0) {
                break;
            }
            $used = min($origin['remaining'], $remaining);
            $allocations[] = ['return_movement_id' => $originId, 'amount_cents' => $used];
            $remaining -= $used;
        }

        return ['return_consumption' => [
            'component' => $component, 'unattributed_cents' => $unattributed, 'allocations' => $allocations, 'verified' => true,
        ]];
    }

    // Callers that change balances or decide whether to reverse a payment hold the investor's write lock.
    public function state(Investor $investor): array
    {
        $state = array_fill_keys(array_keys(self::COMPONENTS), []);
        $consumptions = [];
        foreach ($investor->capitalMovements()->reorder('id')->cursor() as $movement) {
            $metadata = $movement->metadata ?? [];
            if (in_array($movement->type, ['payment_returns_recorded', 'returns_recorded'], true)) {
                foreach (self::COMPONENTS as $component => $type) {
                    $amount = Money::cents($metadata[$component] ?? 0);
                    if ($amount > 0) {
                        $state[$component][$movement->id] = ['remaining' => $amount, 'used' => 0, 'uncertain' => false];
                    }
                }

                continue;
            }
            if ($movement->type === 'payment_returns_reversed') {
                $origin = $metadata['reversed_return_movement_id'] ?? null;
                foreach (array_keys(self::COMPONENTS) as $component) {
                    if (isset($state[$component][$origin])) {
                        $state[$component][$origin]['remaining'] = 0;
                        $state[$component][$origin]['uncertain'] = true;
                    } elseif ($origin === null) {
                        $this->markUncertain($state[$component]);
                    }
                }

                continue;
            }
            $component = array_search($movement->type, self::COMPONENTS, true);
            if ($component !== false) {
                $record = $metadata['return_consumption'] ?? [];
                if (! is_array($record) || ! $this->validUse($record, $component, Money::cents($movement->amount), $state[$component])) {
                    $this->markUncertain($state[$component]);

                    continue;
                }
                foreach ($record['allocations'] as $allocation) {
                    $origin = $allocation['return_movement_id'];
                    $state[$component][$origin]['remaining'] -= $allocation['amount_cents'];
                    $state[$component][$origin]['used'] += $allocation['amount_cents'];
                }
                $consumptions[$movement->id] = $record;

                continue;
            }
            if ($movement->type === 'returns_reinvested') {
                foreach (array_keys(self::COMPONENTS) as $name) {
                    $this->markUncertain($state[$name]);
                }

                continue;
            }
            $canceled = $metadata['cancels_movement_id'] ?? null;
            if (isset($consumptions[$canceled])) {
                $record = $consumptions[$canceled];
                $component = $record['component'];
                if ($movement->type === 'cancel_'.self::COMPONENTS[$component]) {
                    foreach ($record['allocations'] as $allocation) {
                        $origin = $allocation['return_movement_id'];
                        $state[$component][$origin]['remaining'] += $allocation['amount_cents'];
                        $state[$component][$origin]['used'] -= $allocation['amount_cents'];
                    }
                    unset($consumptions[$canceled]);
                }
            }
        }

        return $state;
    }

    private function markUncertain(array &$origins): void
    {
        foreach ($origins as &$origin) {
            $origin['uncertain'] = $origin['uncertain'] || $origin['remaining'] > 0;
        }
    }

    private function validUse(array $record, string $component, int $amount, array $origins): bool
    {
        if (($record['verified'] ?? false) !== true || ($record['component'] ?? null) !== $component
            || ! is_int($record['unattributed_cents'] ?? null) || $record['unattributed_cents'] < 0
            || ! is_array($record['allocations'] ?? null)) {
            return false;
        }
        $reconciled = $record['unattributed_cents'];
        $seen = [];
        foreach ($record['allocations'] as $allocation) {
            if (! is_array($allocation)) {
                return false;
            }
            $origin = $allocation['return_movement_id'] ?? null;
            $used = $allocation['amount_cents'] ?? null;
            if (! is_int($origin) || isset($seen[$origin]) || ! is_int($used) || $used <= 0
                || ! isset($origins[$origin]) || $origins[$origin]['uncertain'] || $used > $origins[$origin]['remaining']) {
                return false;
            }
            $seen[$origin] = true;
            $reconciled += $used;
        }

        return $reconciled === $amount;
    }
}
