<?php

namespace App\Domain\Cajas\V1;

use App\Support\JsonData;
use Illuminate\Support\Facades\DB;

class CutPayouts
{
    public function __construct(private IntegrationAccess $access) {}

    public function netAmounts(array $cut, int $status): array
    {
        $payouts = array_column($cut['payouts'], null, 'payout_id');
        $net = array_map(fn (array $payout) => $payout['amount_cents'], $payouts);

        foreach ($cut['payout_returns'] ?? [] as $return) {
            $id = $return['payout_id'];
            if (! isset($payouts[$id]) || $return['received_on'] < $payouts[$id]['paid_on']
                || $return['amount_cents'] > $net[$id]) {
                $this->access->fail($status, 'PAYOUT_RETURN_INVALID', 'La devolución debe corresponder a un egreso del corte, respetar su fecha y no superar su saldo.', [
                    ['payout_id' => $id, 'payout_return_id' => $return['payout_return_id']],
                ]);
            }
            $net[$id] -= $return['amount_cents'];
        }

        return $net;
    }

    // Reserve physical references in the acceptance transaction, outside the later financial application.
    public function remember(array $cut): void
    {
        $references = [];
        foreach ($cut['payouts'] as $payout) {
            $references[$payout['payout_id']] = ['kind' => 'payout', 'details' => $payout];
        }
        foreach ($cut['payout_returns'] ?? [] as $return) {
            $id = $return['payout_return_id'];
            if (isset($references[$id])) {
                $this->conflict($id);
            }
            $references[$id] = ['kind' => 'return', 'details' => $return];
        }

        $stored = DB::table('cajas_v1_cut_payout_references');
        foreach ((clone $stored)->where('cut_id', $cut['cut_id'])->pluck('public_id') as $id) {
            if (! isset($references[$id])) {
                $this->conflict($id);
            }
        }

        ksort($references);
        foreach ($references as $id => $reference) {
            $stored->insertOrIgnore([
                'public_id' => (string) $id, 'cut_id' => $cut['cut_id'], 'operator' => $cut['operator'],
                'kind' => $reference['kind'], 'first_version' => $cut['version'],
                'details' => json_encode($reference['details'], JSON_THROW_ON_ERROR),
            ]);
            $previous = (clone $stored)->where('public_id', (string) $id)->lockForUpdate()->first();
            if (! $previous || $previous->public_id !== (string) $id || $previous->cut_id !== $cut['cut_id']
                || $previous->operator !== $cut['operator'] || $previous->kind !== $reference['kind']
                || ! JsonData::equals(json_decode($previous->details, true, flags: JSON_THROW_ON_ERROR), $reference['details'])) {
                $this->conflict((string) $id);
            }
        }
    }

    public function appliedCustodyEntry(string $publicId, string $kind): ?array
    {
        $reference = DB::table('cajas_v1_cut_payout_references')->where('public_id', $publicId)->first();
        if (! $reference || $reference->public_id !== $publicId || $reference->kind !== $kind) {
            return null;
        }

        $body = DB::table('cajas_v1_cut_executions')->where('cut_id', $reference->cut_id)
            ->where('operator', $reference->operator)->where('status', 'applied')->latest('version')->value('body');
        $cut = $body === null ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $list = $kind === 'return' ? 'payout_returns' : 'payouts';
        $idField = $kind === 'return' ? 'payout_return_id' : 'payout_id';
        $entry = collect($cut[$list] ?? [])->first(fn (array $entry) => $entry[$idField] === $publicId);
        if ($entry === null || ! JsonData::equals($entry, json_decode($reference->details, true, flags: JSON_THROW_ON_ERROR))) {
            return null;
        }

        return ['cut_id' => $reference->cut_id, 'operator' => $reference->operator, 'entry' => $entry];
    }

    private function conflict(string $id): never
    {
        $this->access->fail(409, 'PAYOUT_REFERENCE_CONFLICT', 'La referencia del egreso o devolución debe conservar sus datos y pertenecer al mismo corte.', [
            ['reference_id' => $id],
        ]);
    }
}
