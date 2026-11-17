<?php

namespace App\Domain\Cajas;

use App\Domain\Loans\InstallmentPaymentPolicy;
use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\PaymentApplicationService;
use App\Models\CashCollectionCut;
use App\Models\CashPendingLoan;
use App\Models\CashTestLoan;
use App\Models\Client;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CashLoanRehearsal
{
    public function enforce(): void
    {
        CajasSandbox::enforce();
        $database = DB::connection()->getDatabaseName();
        $expected = config('cajas.loan_rehearsal_database');
        $isolated = $database === ':memory:'
            ? app()->environment('testing')
            : is_string($expected) && realpath($expected) && realpath($expected) === realpath($database);
        abort_unless($isolated && config('cajas.loan_rehearsal') && DB::connection()->getDriverName() === 'sqlite'
            && DB::table('cash_loan_rehearsal')->where('name', 'isolated')->exists()
            && in_array(config('mail.default'), ['array', 'log'], true)
            && in_array(config('queue.default'), ['sync', 'null'], true), 403, 'El motor de Préstamos requiere una copia aislada habilitada para el ensayo.');
    }

    public function project(Loan $loan): CashTestLoan
    {
        $this->enforce();

        return DB::transaction(function () use ($loan): CashTestLoan {
            $loan = Loan::with('client')->lockForUpdate()->findOrFail($loan->id);
            abort_unless($loan->status === 'active' && $loan->operator_id
                && in_array($loan->calculation_method, ['regular', 'rounded'], true), 422, 'Selecciona un préstamo activo compatible con operador.');
            abort_unless(DB::table('cash_test_operators')->where('operator_id', $loan->operator_id)->exists(), 422, 'Habilita primero al operador en la copia del ensayo.');
            abort_if($loan->installments()->where('remaining_amount', '>', 0)
                ->where(fn ($query) => $query->where('administration_fee_amount', '>', 0)->orWhere('interest_vat_amount', '>', 0))->exists(), 422, 'Este ensayo no captura cobros con comisiones o IVA.');
            $projection = CashTestLoan::firstOrCreate(['loan_id' => $loan->id], [
                'public_id' => (string) Str::ulid(), 'operator_id' => $loan->operator_id,
                'folio' => $loan->folio, 'client_name' => trim($loan->client->first_name.' '.$loan->client->last_name),
                'terms' => [], 'calculation_method' => $loan->calculation_method, 'active' => true, 'collectible' => true,
            ]);
            foreach ($loan->installments()->where('remaining_amount', '>', 0)->lockForUpdate()->get() as $letter) {
                $parts = app(InstallmentPaymentPolicy::class)->remainingComponentsCents($letter);
                abort_unless(array_sum($parts) === Money::cents($letter->remaining_amount), 422, 'Una letra no coincide con sus componentes operativos.');
                $projection->installments()->firstOrCreate(['installment_id' => $letter->id], [
                    'number' => $letter->number, 'due_date' => $letter->due_date,
                    'principal_cents' => $parts['principal'], 'interest_cents' => $parts['interest'],
                    'source_digest' => $this->digest($letter),
                ]);
            }

            return $projection;
        });
    }

    public function applied(CashCollectionCut $cut): bool
    {
        return DB::table('cash_loan_payment_links')->join('cash_cut_items', 'cash_cut_items.id', '=', 'cash_loan_payment_links.cash_cut_item_id')
            ->where('cash_cut_items.cash_collection_cut_id', $cut->id)->exists();
    }

    public function validate(CashCollectionCut $cut, bool $lock = false): array
    {
        $items = $cut->items()->with('installment.loan')->orderBy('id')->get();
        if (! $items->contains(fn ($item) => $item->installment->loan->loan_id !== null)) {
            return [];
        }
        $this->enforce();
        $groups = [];
        foreach ($items as $item) {
            $source = $item->installment;
            abort_unless($source->installment_id && $source->loan->loan_id, 409, 'Separa los cobros ficticios de los vinculados a Préstamos.');
            $query = Installment::query()->with('loan');
            $letter = ($lock ? $query->lockForUpdate() : $query)->findOrFail($source->installment_id);
            abort_unless($letter->loan_id === $source->loan->loan_id && $letter->loan->operator_id === $cut->operator_id
                && $letter->loan->status === 'active' && $letter->loan->calculation_method === $source->loan->calculation_method
                && $source->source_digest === $this->digest($letter), 409, 'Cambió la letra en Préstamos. Revisa el corte y su respaldo.');
            abort_if(CollectionMovement::where('loan_id', $letter->loan_id)->where('confirmation_status', 'reported')->exists(),
                409, 'Hay otro cobro por confirmar en este préstamo. Concilia antes de autorizar.');
            abort_if($item->kind === 'general_capital' && ! app(InstallmentPaymentPolicy::class)->isFutureMonth($letter, $cut->cut_on->toDateString()),
                422, 'El ensayo solo admite el adelanto general sobre letras futuras.');
            $groups[] = [$item, $letter];
        }

        return $groups;
    }

    // Called inside the same transaction that authorizes the cut.
    public function apply(User $user, CashCollectionCut $cut): int
    {
        $groups = $this->validate($cut, true);
        if ($groups !== []) {
            abort_if((int) $cut->reviews()->where('action', 'submitted')->latest('id')->value('created_by') === $user->id,
                403, 'Otra persona debe autorizar el corte.');
        }
        foreach ($groups as [$item, $letter]) {
            $letter->refresh();
            abort_if($item->amount_cents > Money::cents($letter->remaining_amount), 409, 'El pago supera el saldo de su letra.');
            $capital = in_array($item->kind, ['capital', 'general_capital'], true);
            $movement = CollectionMovement::create([
                'public_id' => (string) Str::ulid(), 'folio' => 'CAJAS-'.$item->id,
                'idempotency_key' => 'cajas-cut-item-'.$item->id,
                'loan_id' => $letter->loan_id, 'target_installment_id' => $letter->id, 'operator_id' => $cut->operator_id,
                'registered_by' => $item->created_by, 'operated_on' => $cut->cut_on->toDateString(),
                'registered_at' => $item->created_at, 'contract_amount' => Money::decimal($item->amount_cents),
                'affects_investors' => true, 'type' => $capital ? 'capital_advance' : 'ordinary',
                'reference' => $item->kind === 'capital_interest' ? PaymentApplicationService::FUTURE_MONTH_INTEREST_REFERENCE : null,
                'notes' => 'Ensayo Cajas · '.$cut->public_id, 'confirmation_status' => 'reported',
            ]);
            try {
                app(PaymentApplicationService::class)->confirm($movement, $user->id);
            } catch (RuntimeException $exception) {
                abort(409, $exception->getMessage());
            }
            $allocations = $movement->allocations()->get();
            abort_unless($allocations->count() === 1 && $allocations->first()->installment_id === $letter->id
                && Money::cents($allocations->first()->amount) === $item->amount_cents, 409, 'La aplicación difiere del cobro revisado.');
            DB::table('cash_loan_payment_links')->insert(['cash_cut_item_id' => $item->id, 'collection_movement_id' => $movement->id]);
        }

        return count($groups);
    }

    public function formalize(CashCollectionCut $cut, CashPendingLoan $credit): Loan
    {
        $this->enforce();
        $terms = $credit->terms;
        abort_unless($terms['calculation_method'] === 'regular', 422, 'La conversión integrada de este ensayo admite créditos regulares.');
        abort_unless(filled($terms['phone'] ?? null), 422, 'El alta integrada requiere el teléfono del cliente.');
        $client = Client::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $cut->operator_id,
            'first_name' => $credit->client_name, 'last_name' => '', 'phone' => $terms['phone'] ?? null,
            'status' => 'active',
        ]);
        $loan = app(LoanFormalizer::class)->create($client, [
            ...$terms, 'operator_id' => $cut->operator_id, 'rounding_increment' => $terms['selected_option'] === 'hundreds' ? 100 : 10,
        ]);
        $actual = $loan->installments()->orderBy('number')->get();
        abort_unless($actual->count() === count($credit->schedule['installments'])
            && Money::cents($loan->contract_total) === $credit->schedule['total_cents'], 409, 'El calendario formalizado no coincide con el autorizado.');
        foreach ($credit->schedule['installments'] as $index => $expected) {
            $letter = $actual[$index];
            abort_unless($letter->due_date->toDateString() === $expected['due_date']
                && Money::cents($letter->contract_amount) === $expected['amount_cents']
                && Money::cents($letter->principal_amount) === $expected['principal_cents']
                && Money::cents($letter->interest_amount) === $expected['interest_cents']
                && Money::cents($letter->administration_fee_amount) === $expected['administration_fee_cents']
                && Money::cents($letter->interest_vat_amount) === $expected['interest_vat_cents'], 409, 'Una letra formalizada no coincide con la cotización autorizada.');
        }

        return $loan;
    }

    private function digest(Installment $letter): string
    {
        return hash('sha256', json_encode([$letter->getRawOriginal(), $letter->allocations()->orderBy('id')->get()->map->getRawOriginal()->all()], JSON_THROW_ON_ERROR));
    }
}
