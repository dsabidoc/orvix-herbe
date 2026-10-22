<?php

namespace App\Domain\Cuts;

use App\Models\AuditEvent;
use App\Models\WeeklyCut;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CutReceivedCash
{
    private const DELIVERY_ACTIONS = ['weekly_cut.delivery_opening_balance', 'weekly_cut.delivery_recorded'];

    public function deliveryCutIdsForPeriod(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return AuditEvent::query()
            ->select('auditable_id')
            ->where('auditable_type', WeeklyCut::class)
            ->whereIn('action', self::DELIVERY_ACTIONS)
            ->whereBetween('after->received_on', [$start->toDateString(), $end->toDateString()]);
    }

    public function total(Builder $cutQuery, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $cuts = (clone $cutQuery)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('confirmed_at', [$start->startOfDay(), $end->endOfDay()])
                    ->orWhereIn('id', $this->deliveryCutIdsForPeriod($start, $end));
            })
            ->get(['id', 'received_total', 'confirmed_at']);

        return (int) $this->amountsForCuts($cuts, $start, $end)->sum();
    }

    /**
     * Receipt history replaces the cumulative cut total; adding both would
     * count the same cash twice. Older cuts retain their recorded payment date.
     * No cut needs to be closed to include money already received.
     *
     * @return Collection<int, int> Cut ID => amount received in cents
     */
    public function amountsForCuts(Collection $cuts, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): Collection
    {
        if ($cuts->isEmpty()) {
            return collect();
        }

        $events = AuditEvent::query()
            ->where('auditable_type', WeeklyCut::class)
            ->whereIn('auditable_id', $cuts->pluck('id'))
            ->whereIn('action', self::DELIVERY_ACTIONS)
            ->get(['auditable_id', 'after'])
            ->groupBy('auditable_id');

        return $cuts->mapWithKeys(function (WeeklyCut $cut) use ($events, $start, $end) {
            $deliveries = $events->get($cut->id);

            if ($deliveries !== null) {
                $cents = $deliveries->sum(fn (AuditEvent $event) => $this->inPeriod($event->after['received_on'] ?? null, $start, $end)
                    ? Money::cents($event->after['amount'] ?? 0)
                    : 0);
            } else {
                $cents = $cut->confirmed_at && $this->inPeriod($cut->confirmed_at->toDateString(), $start, $end)
                    ? Money::cents($cut->received_total)
                    : 0;
            }

            return [$cut->id => (int) $cents];
        });
    }

    private function inPeriod(?string $date, ?CarbonImmutable $start, ?CarbonImmutable $end): bool
    {
        if ($start === null && $end === null) {
            return true;
        }

        return $date !== null
            && ($start === null || $date >= $start->toDateString())
            && ($end === null || $date <= $end->toDateString());
    }
}
