<?php

namespace App\Domain\Loans;

use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentApplicationService
{
    public function confirm(CollectionMovement $movement, int $confirmedByUserId): CollectionMovement
    {
        return DB::transaction(function () use ($movement, $confirmedByUserId) {
            $movement = CollectionMovement::query()
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($movement->confirmation_status !== 'reported') {
                throw new RuntimeException('Este movimiento ya no esta pendiente de confirmacion.');
            }

            $remainingCents = Money::cents($movement->contract_amount);
            $installments = $movement->loan->installments()
                ->where('remaining_amount', '>', 0)
                ->when(
                    $movement->type === 'advance',
                    fn ($query) => $query->orderByDesc('number'),
                    fn ($query) => $query
                        ->when($movement->targetInstallment, fn ($query) => $query->where('number', '>=', $movement->targetInstallment->number))
                        ->orderBy('number'),
                )
                ->lockForUpdate()
                ->get();

            foreach ($installments as $installment) {
                if ($remainingCents <= 0) {
                    break;
                }

                $installmentRemaining = Money::cents($installment->remaining_amount);
                $applied = min($remainingCents, $installmentRemaining);
                $newRemaining = $installmentRemaining - $applied;
                $newApplied = Money::cents($installment->applied_amount) + $applied;

                $installment->update([
                    'applied_amount' => Money::decimal($newApplied),
                    'remaining_amount' => Money::decimal($newRemaining),
                    'status' => $newRemaining === 0 ? $this->statusForCoveredInstallment($movement->type) : 'partial',
                ]);

                PaymentAllocation::query()->create([
                    'collection_movement_id' => $movement->id,
                    'installment_id' => $installment->id,
                    'amount' => Money::decimal($applied),
                ]);

                $remainingCents -= $applied;
            }

            if ($remainingCents > 0) {
                throw new RuntimeException('El monto excede el saldo contractual disponible.');
            }

            $movement->update([
                'confirmation_status' => 'applied',
                'confirmed_by' => $confirmedByUserId,
                'confirmed_at' => now('America/Merida'),
            ]);

            AuditEvent::query()->create([
                'user_id' => $confirmedByUserId,
                'action' => 'collection_movement.confirmed',
                'auditable_type' => CollectionMovement::class,
                'auditable_id' => $movement->id,
                'after' => [
                    'folio' => $movement->folio,
                    'type' => $movement->type,
                    'contract_amount' => $movement->contract_amount,
                    'allocations' => $movement->allocations()->count(),
                ],
                'related_idempotency_key' => $movement->idempotency_key,
            ]);

            return $movement->fresh(['loan.client', 'allocations.installment']);
        });
    }

    private function statusForCoveredInstallment(string $movementType): string
    {
        return $movementType === 'advance' ? 'advanced' : 'confirmed';
    }
}
