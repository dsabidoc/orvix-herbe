<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RepairHistoricCapitalAdvances extends Command
{
    protected $signature = 'loans:repair-historic-capital-advances {--apply : Aplica la corrección; sin esta opción solo se valida}';

    protected $description = 'Corrige adelantos a capital históricos que conservaron el interés futuro como saldo parcial.';

    /**
     * @var list<array{loan_folio:string,installment_number:int,movement_folio:string,principal_cents:int,remaining_cents:int}>
     */
    private const TARGETS = [
        ['loan_folio' => 'RUB-060426-01', 'installment_number' => 22, 'movement_folio' => 'MOV-261012-5559', 'principal_cents' => 163000, 'remaining_cents' => 118000],
        ['loan_folio' => 'RUB-190325-01', 'installment_number' => 25, 'movement_folio' => 'MOV-261002-5469', 'principal_cents' => 180000, 'remaining_cents' => 130000],
        ['loan_folio' => 'RUB-190325-01', 'installment_number' => 26, 'movement_folio' => 'MOV-261002-5468', 'principal_cents' => 180000, 'remaining_cents' => 130000],
        ['loan_folio' => 'RUB-190325-01', 'installment_number' => 27, 'movement_folio' => 'MOV-261002-5467', 'principal_cents' => 180000, 'remaining_cents' => 130000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 31, 'movement_folio' => 'MOV-261002-5495', 'principal_cents' => 300000, 'remaining_cents' => 216000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 32, 'movement_folio' => 'MOV-261002-5494', 'principal_cents' => 300000, 'remaining_cents' => 216000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 33, 'movement_folio' => 'MOV-261002-5493', 'principal_cents' => 300000, 'remaining_cents' => 216000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 34, 'movement_folio' => 'MOV-261002-5492', 'principal_cents' => 300000, 'remaining_cents' => 216000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 35, 'movement_folio' => 'MOV-261002-5491', 'principal_cents' => 300000, 'remaining_cents' => 216000],
        ['loan_folio' => 'RUB-300725-01', 'installment_number' => 36, 'movement_folio' => 'MOV-261002-5490', 'principal_cents' => 300000, 'remaining_cents' => 216000],
    ];

    public function handle(): int
    {
        if (! $this->option('apply')) {
            $this->components->warn('Modo de validación: no se cambiará ningún dato. Usa --apply para ejecutar la corrección.');
        }

        try {
            $repaired = DB::transaction(function (): array {
                $repaired = [];

                foreach (self::TARGETS as $target) {
                    $installment = Installment::query()
                        ->whereHas('loan', fn ($query) => $query->where('folio', $target['loan_folio'])->where('calculation_method', '!=', 'interest_only'))
                        ->where('number', $target['installment_number'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $movement = CollectionMovement::query()
                        ->where('folio', $target['movement_folio'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $allocationCents = Money::cents(PaymentAllocation::query()
                        ->where('collection_movement_id', $movement->id)
                        ->where('installment_id', $installment->id)
                        ->sum('amount'));

                    $this->assertExpectedState($installment, $movement, $allocationCents, $target);

                    $before = [
                        'status' => $installment->status,
                        'remaining_amount' => $installment->remaining_amount,
                        'applied_amount' => $installment->applied_amount,
                    ];

                    if ($this->option('apply')) {
                        $installment->update([
                            'remaining_amount' => '0.00',
                            'status' => 'advanced',
                        ]);

                        AuditEvent::query()->create([
                            'action' => 'installment.historic_capital_advance_repaired',
                            'auditable_type' => Installment::class,
                            'auditable_id' => $installment->id,
                            'before' => $before,
                            'after' => [
                                'status' => 'advanced',
                                'remaining_amount' => '0.00',
                                'applied_amount' => $installment->applied_amount,
                                'collection_movement_folio' => $movement->folio,
                            ],
                            'reason' => 'Corrección histórica autorizada: un adelanto de capital futuro debe dejar la letra adelantada, sin interés futuro pendiente.',
                            'related_idempotency_key' => $movement->idempotency_key,
                        ]);
                    }

                    $repaired[] = [
                        $target['loan_folio'],
                        (string) $target['installment_number'],
                        $movement->folio,
                        Money::decimal($target['remaining_cents']),
                    ];
                }

                return $repaired;
            });
        } catch (\Throwable $exception) {
            $this->components->error('No se aplicó ninguna corrección: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Crédito', 'Letra', 'Movimiento', 'Interés pendiente retirado'], $repaired);
        $this->components->info($this->option('apply')
            ? 'Se corrigieron 10 letras. Movimientos, asignaciones e importes cobrados se conservaron.'
            : 'Las 10 letras validaron correctamente. No se modificó ningún dato.');

        return self::SUCCESS;
    }

    /**
     * @param array{loan_folio:string,installment_number:int,movement_folio:string,principal_cents:int,remaining_cents:int} $target
     */
    private function assertExpectedState(Installment $installment, CollectionMovement $movement, int $allocationCents, array $target): void
    {
        $valid = $movement->target_installment_id === $installment->id
            && $movement->type === 'capital_advance'
            && $movement->confirmation_status === 'applied'
            && Money::cents($movement->contract_amount) === $target['principal_cents']
            && Money::cents($installment->principal_amount) === $target['principal_cents']
            && Money::cents($installment->remaining_amount) === $target['remaining_cents']
            && Money::cents($installment->applied_amount) === $target['principal_cents']
            && $allocationCents === $target['principal_cents']
            && $installment->status === 'partial';

        if (! $valid) {
            throw new RuntimeException("La letra {$target['loan_folio']} #{$target['installment_number']} ya no coincide con el estado histórico validado; se detuvo la corrección.");
        }
    }
}
