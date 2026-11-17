<?php

namespace App\Domain\Cajas;

use App\Domain\Loans\InstallmentPaymentPolicy;
use App\Models\AuditEvent;
use App\Models\CashCollectionCut;
use App\Models\CashCutItem;
use App\Models\CashPendingLoan;
use App\Models\CashTestInstallment;
use App\Models\CashTestLoan;
use App\Models\CollectionMovement;
use App\Models\Installment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\JsonData;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashCutService
{
    public const PERMISSIONS = ['cajas.cuts.prepare', 'cajas.payments.report', 'cajas.advances.report', 'cajas.advances.include-interest', 'cajas.pending-loans.create', 'cajas.pending-loans.view', 'cajas.pending-loans.authorize'];

    public function authorize(User $user, int $operatorId, ?string $permission = null): void
    {
        CajasSandbox::enforce();
        $user = $user->fresh();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at && $user->can('cajas.access')
            && $user->can('cajas.collections.loans.view') && (! $permission || $user->can($permission)), 403);
        abort_unless(DB::table('cash_test_operators')->where('operator_id', $operatorId)->exists()
            && $user->cashOperators()->whereKey($operatorId)->where('status', 'active')->exists(), 404);
    }

    public function open(User $user, array $input): CashCollectionCut
    {
        $data = Validator::make($input, ['operator_id' => ['required', 'integer'], 'cut_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Merida')->toDateString()]])->validate();
        $this->authorize($user, (int) $data['operator_id'], 'cajas.cuts.prepare');

        return DB::transaction(function () use ($user, $data): CashCollectionCut {
            $this->lockOperator((int) $data['operator_id']);
            $this->authorize($user, (int) $data['operator_id'], 'cajas.cuts.prepare');
            $existing = CashCollectionCut::where('operator_id', $data['operator_id'])->whereDate('cut_on', $data['cut_on'])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $cut = CashCollectionCut::create([...$data, 'public_id' => (string) Str::ulid(), 'created_by' => $user->id, 'version' => 1, 'status' => 'draft']);
            $this->audit($user, $cut, 'opened', [], ['cut_on' => $data['cut_on']], null);

            return $cut;
        }, 5);
    }

    /** Reuse the Préstamos policy with unsaved models and loaded synthetic reservations. */
    public function availability(CashTestInstallment $letter, string $date, ?int $excludeItem = null): array
    {
        abort_unless($letter->loan->collectible, 409, 'Los cobros de esta modalidad todavía no están habilitados en la prueba.');
        $reservations = $letter->reservations()->when($excludeItem, fn ($q) => $q->whereKeyNot($excludeItem))->orderBy('id')->get();
        // Ordinary reservations close by their sum; only advances explicitly waive the remaining components.
        $closed = $reservations->contains(fn ($item) => $item->closes_installment && $item->kind !== 'ordinary');
        $base = $letter->principal_cents + $letter->interest_cents;
        $installment = new Installment(['principal_amount' => Money::decimal($letter->principal_cents),
            'interest_amount' => Money::decimal($letter->interest_cents), 'due_date' => $letter->due_date,
            'remaining_amount' => Money::decimal($closed ? 0 : max(0, $base - $reservations->sum('amount_cents')))]);
        $installment->setRelation('allocations', $reservations->map(function ($item) {
            $allocation = new PaymentAllocation(['amount' => Money::decimal($item->amount_cents)]);
            $allocation->setRelation('movement', new CollectionMovement(['type' => in_array($item->kind, ['capital', 'general_capital']) ? 'capital_advance' : 'ordinary']));

            return $allocation;
        }));
        $policy = app(InstallmentPaymentPolicy::class);
        $components = $policy->remainingComponentsCents($installment);

        return ['principal' => $components['principal'], 'interest' => $components['interest'],
            'total' => Money::cents($installment->remaining_amount), 'future' => $policy->isFutureMonth($installment, $date),
            'reserved' => $reservations->isNotEmpty()];
    }

    public function addLetters(User $user, CashCollectionCut $cut, array $input): CashCollectionCut
    {
        $data = Validator::make($input, [
            'installment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'installment_ids.*' => ['integer', 'distinct'], 'amounts' => ['sometimes', 'array'],
            'amounts.*' => ['nullable', 'string', 'regex:/^[0-9]{1,9}(\.[0-9]{1,2})?$/'],
            'quote_hashes' => ['required', 'array'], 'quote_hashes.*' => ['required', 'string', 'size:64'],
            'include_interest_ids' => ['sometimes', 'array'], 'include_interest_ids.*' => ['integer', 'distinct'],
        ], [
            'installment_ids.required' => 'Selecciona al menos una letra para agregarla al corte.',
            'installment_ids.max' => 'Puedes agregar hasta 100 letras a la vez.',
            'amounts.*.regex' => 'Revisa los importes. Usa números con hasta dos decimales.',
        ])->validate();

        return $this->command($user, $cut, $input, 'letters', $data, 'cajas.payments.report', function (CashCollectionCut $cut) use ($user, $data): array {
            $items = [];
            foreach ($data['installment_ids'] as $id) {
                $letter = CashTestInstallment::whereKey($id)->whereHas('loan', fn ($q) => $q->where('operator_id', $cut->operator_id)->where('active', true))->with('loan')->firstOrFail();
                $available = $this->availability($letter, $cut->cut_on->toDateString());
                abort_unless(hash_equals($this->letterHash($letter, $cut->cut_on->toDateString()), (string) ($data['quote_hashes'][$id] ?? '')), 409, 'Cambió una letra o su reserva. Actualiza la lista antes de agregarla.');
                $include = in_array($id, $data['include_interest_ids'] ?? []);
                if ($available['future']) {
                    $this->authorize($user, $cut->operator_id, 'cajas.advances.report');
                    if ($include) {
                        $this->authorize($user, $cut->operator_id, 'cajas.advances.include-interest');
                    }
                    $kind = $include ? 'capital_interest' : 'capital';
                    $amount = $include ? $available['total'] : $available['principal'];
                } else {
                    abort_if($include, 422, 'La opción de adelantar intereses corresponde a letras futuras.');
                    $kind = 'ordinary';
                    $amount = filled($data['amounts'][$id] ?? null) ? Money::cents($data['amounts'][$id]) : $available['total'];
                }
                if ($amount <= 0 || $amount > $available['total']) {
                    throw ValidationException::withMessages(['installment_ids' => 'Una letra ya está reservada o el importe excede lo disponible. Actualiza la lista.']);
                }
                abort_unless(in_array($letter->loan->calculation_method, ['regular', 'rounded']), 422, 'Esta modalidad requiere su flujo específico de capital.');
                $items[] = $this->item($user, $cut, $letter, $kind, $amount, $available['future'] || $amount === $available['total'])->id;
            }

            return ['item_ids' => $items];
        });
    }

    public function generalAdvance(User $user, CashCollectionCut $cut, array $input): CashCollectionCut
    {
        $data = Validator::make($input, ['loan_id' => ['required', 'integer'], 'letter_count' => ['required', 'integer', 'between:1,120'], 'loan_hash' => ['required', 'string', 'size:64']])->validate();

        return $this->command($user, $cut, $input, 'general_advance', $data, 'cajas.advances.report', function (CashCollectionCut $cut) use ($user, $data): array {
            $loan = CashTestLoan::whereKey($data['loan_id'])->where('operator_id', $cut->operator_id)->where('active', true)->firstOrFail();
            abort_unless(in_array($loan->calculation_method, ['regular', 'rounded']), 422);
            abort_unless(hash_equals($this->loanHash($loan, $cut->cut_on->toDateString()), $data['loan_hash']), 409, 'Cambió el capital disponible. Actualiza la vista del adelanto.');
            $letters = $loan->installments()->orderByDesc('number')->get()->filter(fn ($letter) => $this->availability($letter, $cut->cut_on->toDateString())['principal'] > 0)->take($data['letter_count']);
            abort_unless($letters->count() === (int) $data['letter_count'], 422, 'No hay tantas letras con capital disponible.');
            $ids = [];
            foreach ($letters as $letter) {
                $available = $this->availability($letter, $cut->cut_on->toDateString());
                $ids[] = $this->item($user, $cut, $letter, 'general_capital', $available['principal'], true)->id;
            }

            return ['item_ids' => $ids];
        });
    }

    public function pendingLoan(User $user, CashCollectionCut $cut, array $input): CashCollectionCut
    {
        $quote = app(CashPendingQuote::class)->calculate($input['terms'] ?? []);
        abort_unless(hash_equals($quote['hash'], (string) ($input['quote_hash'] ?? '')), 409, 'Las condiciones cambiaron. Genera una nueva vista previa.');
        $authorize = filter_var($input['authorize'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->command($user, $cut, $input, 'pending_loan', [$quote['terms'], $quote['hash'], $authorize], 'cajas.pending-loans.create', function (CashCollectionCut $cut) use ($user, $quote, $authorize): array {
            $this->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
            if ($authorize) {
                $this->authorize($user, $cut->operator_id, 'cajas.pending-loans.authorize');
            }
            $terms = $quote['terms'];
            $pending = CashPendingLoan::create(['public_id' => (string) Str::ulid(), 'cash_collection_cut_id' => $cut->id,
                'client_name' => $terms['client_name'], 'phone' => $terms['phone'] ?? null, 'capital_cents' => $quote['schedule']['capital_cents'],
                'status' => $authorize ? 'authorized' : 'draft', 'planned_funding' => $terms['planned_funding'],
                'terms' => $terms, 'schedule' => $quote['schedule'], 'notes' => $terms['notes'] ?? null, 'created_by' => $user->id,
                'authorized_by' => $authorize ? $user->id : null, 'authorized_at' => $authorize ? now() : null]);

            return ['pending_loan_id' => $pending->id, 'conditions_authorized' => $authorize];
        });
    }

    public function remove(User $user, CashCollectionCut $cut, string $type, int $id, array $input): CashCollectionCut
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'max:500']])->validate();
        abort_unless(in_array($type, ['item', 'credit']), 404);
        $permission = $type === 'credit' ? 'cajas.pending-loans.create' : 'cajas.payments.report';

        return $this->command($user, $cut, $input, 'remove_'.$type, [$id, $data['reason']], $permission, function (CashCollectionCut $cut) use ($user, $type, $id): array {
            if ($type === 'credit') {
                $this->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
                $record = $cut->pendingLoans()->whereKey($id)->firstOrFail();
                if ($record->status === 'authorized') {
                    $this->authorize($user, $cut->operator_id, 'cajas.pending-loans.authorize');
                }
                $record->update(['status' => 'cancelled']);
            } else {
                $record = $cut->items()->whereKey($id)->firstOrFail();
                if ($record->kind !== 'ordinary') {
                    $this->authorize($user, $cut->operator_id, 'cajas.advances.report');
                }
                $record->update(['removed_at' => now()]);
            }

            return ['removed_id' => $id];
        });
    }

    public function authorizePending(User $user, CashCollectionCut $cut, int $id, array $input): CashCollectionCut
    {
        Validator::make($input, ['confirmed' => ['accepted']])->validate();

        return $this->command($user, $cut, $input, 'authorize_credit', [$id], 'cajas.pending-loans.authorize', function (CashCollectionCut $cut) use ($user, $id): array {
            $this->authorize($user, $cut->operator_id, 'cajas.pending-loans.view');
            $record = $cut->pendingLoans()->whereKey($id)->firstOrFail();
            abort_unless($record->status === 'draft', 409, 'Estas condiciones ya se autorizaron o se retiraron.');
            $quote = app(CashPendingQuote::class)->calculate($record->terms);
            abort_unless(JsonData::equals($quote['schedule'], $record->schedule), 409, 'El cálculo cambió; prepara un crédito nuevo con las condiciones revisadas.');
            $record->update(['status' => 'authorized', 'authorized_by' => $user->id, 'authorized_at' => now()]);

            return ['pending_loan_id' => $id];
        });
    }

    private function command(User $user, CashCollectionCut $cut, array $input, string $action, array $payload, string $permission, Closure $execute): CashCollectionCut
    {
        $this->authorize($user, $cut->operator_id, $permission);
        $this->authorize($user, $cut->operator_id, 'cajas.cuts.prepare');
        $command = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $fingerprint = hash('sha256', json_encode([$user->id, $cut->id, $action, $payload]));

        return DB::transaction(function () use ($user, $cut, $command, $fingerprint, $execute, $action, $permission, $input): CashCollectionCut {
            $this->lockOperator($cut->operator_id);
            $this->authorize($user, $cut->operator_id, $permission);
            $this->authorize($user, $cut->operator_id, 'cajas.cuts.prepare');
            $current = CashCollectionCut::whereKey($cut->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('cash_cut_commands')->where('idempotency_key', $command['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'La solicitud ya se utilizó con otros datos.');

                return $current;
            }
            abort_unless($current->status === 'draft' && ! $current->custody_locked_at && ! $current->hasPendingCorrection() && $current->version === (int) $command['version'], 409, 'El corte cambió o ya no permite editarse. Actualiza la pantalla.');
            $before = ['version' => $current->version];
            $result = $execute($current);
            $current->increment('version');
            DB::table('cash_cut_commands')->insert(['idempotency_key' => $command['idempotency_key'], 'cash_collection_cut_id' => $current->id, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($user, $current, $action, $before, ['version' => $current->version, ...$result], $input['reason'] ?? null);

            return $current;
        }, 5);
    }

    private function lockOperator(int $id): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('cash_test_operators')->where('operator_id', $id)->update(['operator_id' => $id]);
        }
        abort_unless(DB::table('cash_test_operators')->where('operator_id', $id)->lockForUpdate()->first(), 404);
    }

    public function letterHash(CashTestInstallment $letter, string $date): string
    {
        return hash('sha256', json_encode([$letter->id, $letter->due_date->toDateString(), $letter->principal_cents, $letter->interest_cents,
            $letter->loan->active, $letter->loan->calculation_method, $letter->loan->client_name, $letter->loan->folio,
            $date, $this->availability($letter, $date)]));
    }

    public function loanHash(CashTestLoan $loan, string $date): string
    {
        return hash('sha256', $loan->installments()->orderBy('number')->get()->map(fn ($letter) => $this->letterHash($letter, $date))->implode('|'));
    }

    private function item(User $user, CashCollectionCut $cut, CashTestInstallment $letter, string $kind, int $amount, bool $closes): CashCutItem
    {
        return CashCutItem::create(['cash_collection_cut_id' => $cut->id, 'cash_test_installment_id' => $letter->id,
            'kind' => $kind, 'amount_cents' => $amount, 'closes_installment' => $closes, 'created_by' => $user->id,
            'snapshot' => ['folio' => $letter->loan->folio, 'client_name' => $letter->loan->client_name, 'number' => $letter->number,
                'due_date' => $letter->due_date->toDateString(), 'principal_cents' => $letter->principal_cents, 'interest_cents' => $letter->interest_cents,
                'loan_updated_at' => $letter->loan->updated_at->toISOString(), 'letter_updated_at' => $letter->updated_at->toISOString()]]);
    }

    private function audit(User $user, CashCollectionCut $cut, string $action, array $before, array $after, ?string $reason): void
    {
        AuditEvent::create(['user_id' => $user->id, 'action' => 'cajas.cut.'.$action, 'auditable_type' => CashCollectionCut::class,
            'auditable_id' => $cut->id, 'before' => $before, 'after' => $after, 'reason' => $reason]);
    }
}
