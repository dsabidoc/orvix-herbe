<?php

namespace App\Domain\Cuts;

use App\Models\AuditEvent;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CutDeliveryHistory
{
    // This change is enabled only for the cut requested by the customer.
    public const CUT_PUBLIC_ID = '01M5AS3VH5S76XFVMJZ4NEZB27';

    public function enabled(WeeklyCut $cut): bool
    {
        return $cut->public_id === self::CUT_PUBLIC_ID;
    }

    private function events(WeeklyCut $cut): Builder
    {
        return AuditEvent::query()
            ->where('auditable_type', WeeklyCut::class)
            ->where('auditable_id', $cut->id)
            ->whereIn('action', ['weekly_cut.delivery_opening_balance', 'weekly_cut.delivery_recorded']);
    }

    /** Called only while the cut is locked inside the confirmation transaction. */
    public function record(WeeklyCut $cut, array $data, int $userId): bool
    {
        $key = 'cut-delivery|'.$cut->id.'|'.strtolower($data['delivery_token']);

        if ($this->events($cut)->where('related_idempotency_key', $key)->exists()) {
            return false;
        }

        $beforeCents = Money::cents($cut->received_total);

        if ($beforeCents > 0 && ! $this->events($cut)->exists()) {
            AuditEvent::query()->create([
                'user_id' => $cut->confirmed_by,
                'action' => 'weekly_cut.delivery_opening_balance',
                'auditable_type' => WeeklyCut::class,
                'auditable_id' => $cut->id,
                'after' => [
                    'amount' => Money::decimal($beforeCents),
                    'received_on' => $cut->confirmed_at?->toDateString(),
                    'received_total' => Money::decimal($beforeCents),
                ],
                'reason' => 'Importe acumulado previo. La fecha corresponde a la ultima recepcion registrada; no desglosa entregas anteriores.',
            ]);
        }

        $totalCents = $beforeCents + Money::cents($data['delivery_amount']);
        abort_if($totalCents > 999999999999999, 422, 'El total recibido excede el importe permitido.');

        AuditEvent::query()->create([
            'user_id' => $userId,
            'action' => 'weekly_cut.delivery_recorded',
            'auditable_type' => WeeklyCut::class,
            'auditable_id' => $cut->id,
            'before' => ['received_total' => Money::decimal($beforeCents)],
            'after' => [
                'amount' => Money::decimal(Money::cents($data['delivery_amount'])),
                'received_on' => CarbonImmutable::parse($data['received_on'], 'America/Merida')->toDateString(),
                'received_total' => Money::decimal($totalCents),
            ],
            'reason' => $data['reason'] ?? null,
            'related_idempotency_key' => $key,
        ]);

        return true;
    }

    public function history(WeeklyCut $cut): Collection
    {
        $history = $this->events($cut)->with('user:id,name')->orderBy('id')->get()
            ->map(fn (AuditEvent $event) => [
                'opening' => $event->action === 'weekly_cut.delivery_opening_balance',
                'amount' => $event->after['amount'],
                'received_on' => $event->after['received_on'],
                'user' => $event->user?->name,
                'note' => $event->reason,
            ]);

        if ($history->isEmpty() && Money::cents($cut->received_total) > 0) {
            $history->push([
                'opening' => true,
                'amount' => $cut->received_total,
                'received_on' => $cut->confirmed_at?->toDateString(),
                'user' => $cut->confirmedBy?->name,
                'note' => 'Importe acumulado previo. La fecha corresponde a la ultima recepcion registrada; no desglosa entregas anteriores.',
            ]);
        }

        return $history;
    }
}
