<?php

namespace App\Domain\Cajas\V1;

use App\Domain\Loans\LoanFormalizer;
use App\Domain\Loans\LoanScheduleCalculator;
use App\Models\AuditEvent;
use App\Models\CajasV1PendingCredit;
use App\Models\Client;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Operator;
use App\Models\User;
use App\Support\LoanTerms;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PendingCredits
{
    public function __construct(private IntegrationAccess $access, private LoanScheduleCalculator $calculator) {}

    public function register(array $cut, Operator $operator): array
    {
        $offsets = collect($cut['offsets'])->keyBy('offset_id');
        $funded = collect($cut['pending_credits'])->where('funding.type', 'offset');
        if ($offsets->count() !== $funded->count()) {
            $this->access->fail(409, 'FUNDING_CONFLICT', 'Cada compensación debe corresponder a una propuesta de este corte.');
        }

        $result = [];
        foreach ($cut['pending_credits'] as $proposal) {
            $id = $proposal['pending_credit_id'];
            $reference = $proposal['funding']['type'] === 'offset' ? $proposal['funding']['reference_id'] : null;
            $offset = $reference === null ? null : $offsets->pull($reference);
            $detail = [['pending_credit_id' => $id, 'reference_id' => $reference]];
            if (($reference !== null && (! $offset || $offset['pending_credit_id'] !== $id
                || $offset['amount_cents'] !== $proposal['terms']['principal_cents']))
                || CajasV1PendingCredit::where('public_id', $id)->when($offset, fn ($query) => $query
                    ->orWhere('offset_id', $offset['offset_id']))->exists()) {
                $this->access->fail(409, 'FUNDING_CONFLICT', 'La compensación no corresponde al crédito, no cubre el capital íntegro o ya está vinculada.', $detail);
            }
            if (isset($proposal['client_id']) && ! $this->client($proposal['client_id'], $operator->id)) {
                $this->access->fail(409, 'CLIENT_NOT_FOUND', 'El cliente no está disponible para este operador.', $detail);
            }
            if (! in_array((int) $proposal['terms']['term_months'], LoanTerms::active(), true)) {
                $this->access->fail(422, 'CREDIT_TERMS_INVALID', 'El plazo del crédito no está habilitado.', $detail);
            }

            $calculated = $this->schedule($proposal['terms']);
            $observations = $this->observations($proposal['proposed_schedule'], $calculated);
            if (! $this->validSchedule($calculated, $proposal['terms']['principal_cents'])) {
                $this->access->fail(422, 'CREDIT_TERMS_INVALID', 'Las condiciones no generan un calendario contractual válido.', $detail);
            }
            $credit = CajasV1PendingCredit::create([
                'public_id' => $id, 'cut_id' => $cut['cut_id'], 'cut_version' => $cut['version'], 'operator_id' => $operator->id,
                'offset_id' => $offset['offset_id'] ?? null, 'offset' => $offset, 'proposal' => $proposal,
                'observations' => $observations, 'status' => $observations === [] ? 'pending' : 'requires_review',
            ]);
            $result[] = $this->status($credit);
        }

        return $result;
    }

    public function result(CajasV1PendingCredit $credit): array
    {
        $result = [
            ...$this->status($credit), 'cut_id' => $credit->cut_id, 'cut_version' => $credit->cut_version, 'currency' => 'MXN',
            'funding' => $credit->funding(),
        ];
        if ($credit->status === 'active') {
            $loan = $credit->loan;
            $result += [
                'loan_id' => $loan->public_id, 'loan_folio' => $loan->folio,
                'schedule' => $this->loanSchedule($loan),
            ];
        }

        return $result;
    }

    public function schedule(array $terms): array
    {
        return array_map(fn (array $row) => [
            'number' => $row['number'], 'due_on' => $row['due_date'], 'principal_cents' => $row['principal_cents'],
            'interest_cents' => $row['interest_cents'], 'administration_fee_cents' => $row['administration_fee_cents'],
            'vat_cents' => $row['interest_vat_cents'], 'amount_cents' => $row['amount_cents'],
        ], $this->calculator->calculate($this->loanData($terms))->installments);
    }

    public function reviewSchedule(CajasV1PendingCredit $credit, User $actor, string $scheduleHash): void
    {
        DB::transaction(function () use ($credit, $actor, $scheduleHash): void {
            $credit = $this->lock($credit);
            abort_unless($credit->status === 'requires_review', 409, 'La propuesta ya cambió de estado.');
            $schedule = $this->verifyTermsAndFunding($credit);
            abort_unless(hash_equals($this->scheduleHash($schedule), $scheduleHash), 409, 'El calendario cambió. Vuelve a revisarlo.');

            $credit->update(['reviewed_schedule' => $schedule, 'observations' => [], 'status' => 'pending']);
            $this->audit($credit, $actor, 'schedule_confirmed', ['schedule' => $schedule]);
        }, 5);
    }

    public function formalize(CajasV1PendingCredit $credit, User $actor, LoanFormalizer $formalizer): ?Loan
    {
        return DB::transaction(function () use ($credit, $actor, $formalizer): ?Loan {
            $credit = $this->lock($credit);
            if ($credit->loan_id !== null) {
                return $credit->loan;
            }
            abort_unless($credit->status === 'pending', 409, 'El crédito requiere revisión antes de formalizarse.');
            $terms = $credit->proposal['terms'];
            $schedule = $this->verifyTermsAndFunding($credit);
            $approved = $credit->reviewed_schedule ?? $credit->proposal['proposed_schedule'];
            $observations = $this->observations($approved, $schedule);
            if ($observations !== []) {
                $credit->update(['observations' => $observations, 'status' => 'requires_review']);

                return null;
            }
            $client = $this->formalizationClient($credit);

            $loan = $formalizer->create($client, [...$this->loanData($terms), 'operator_id' => $credit->operator_id]);
            abort_if($this->observations($schedule, $this->loanSchedule($loan)) !== [], 409, 'SCHEDULE_MISMATCH: El calendario formalizado difiere.');
            $credit->update(['loan_id' => $loan->id, 'status' => 'active']);
            $this->audit($credit, $actor, 'formalized', ['loan_id' => $loan->public_id, 'loan_folio' => $loan->folio]);

            return $loan;
        }, 5);
    }

    public function scheduleHash(array $schedule): string
    {
        return hash('sha256', json_encode($schedule, JSON_THROW_ON_ERROR));
    }

    public function currentScheduleObservations(CajasV1PendingCredit $credit): array
    {
        return $this->observations(
            $credit->reviewed_schedule ?? $credit->proposal['proposed_schedule'],
            $this->schedule($credit->proposal['terms']),
        );
    }

    private function status(CajasV1PendingCredit $credit): array
    {
        $result = ['pending_credit_id' => $credit->public_id, 'status' => $credit->status];
        if ($credit->observations !== []) {
            $result['observations'] = $credit->observations;
        }

        return $result;
    }

    private function loanData(array $terms): array
    {
        return [
            'capital' => Money::decimal($terms['principal_cents']),
            'monthly_rate' => (string) BigDecimal::of($terms['monthly_rate_percent'])->withPointMovedLeft(2),
            'term_months' => (int) $terms['term_months'], 'start_date' => $terms['start_on'],
            'first_payment_date' => $terms['first_payment_on'], 'payment_day' => (int) $terms['payment_day'],
            'calculation_method' => 'regular', 'interest_calculation_method' => 'fixed_principal',
            'administration_fee' => '0.00', 'vat_enabled' => false,
            'rounding_increment' => $terms['rounding_option'] === 'hundreds' ? 100 : 10,
        ];
    }

    private function observations(array $proposed, array $calculated): array
    {
        $details = [];
        if (count($proposed) !== count($calculated)) {
            $details[] = ['field' => 'count', 'proposed' => count($proposed), 'calculated' => count($calculated)];
        }
        $byNumber = array_column($proposed, null, 'number');
        foreach ($calculated as $row) {
            $number = $row['number'];
            foreach (['due_on', 'principal_cents', 'interest_cents', 'administration_fee_cents', 'vat_cents', 'amount_cents'] as $field) {
                $value = $byNumber[$number][$field] ?? null;
                if ($value !== $row[$field]) {
                    $details[] = ['number' => $number, 'field' => $field, 'proposed' => $value, 'calculated' => $row[$field]];
                }
            }
        }
        foreach (['principal_cents', 'amount_cents'] as $field) {
            $actual = array_sum(array_column($proposed, $field));
            $expected = array_sum(array_column($calculated, $field));
            if ($actual !== $expected) {
                $details[] = ['field' => 'total_'.$field, 'proposed' => $actual, 'calculated' => $expected];
            }
        }

        return $details === [] ? [] : [[
            'code' => 'SCHEDULE_MISMATCH', 'message' => 'El calendario propuesto difiere del calculado en Préstamos.', 'details' => $details,
        ]];
    }

    private function loanSchedule(Loan $loan): array
    {
        return $loan->installments()->orderBy('number')->get()->map(fn (Installment $letter) => [
            'number' => $letter->number, 'installment_id' => $letter->public_id,
            'due_on' => $letter->due_date->toDateString(), 'principal_cents' => Money::cents($letter->principal_amount),
            'interest_cents' => Money::cents($letter->interest_amount),
            'administration_fee_cents' => Money::cents($letter->administration_fee_amount),
            'vat_cents' => Money::cents($letter->interest_vat_amount), 'amount_cents' => Money::cents($letter->contract_amount),
        ])->all();
    }

    private function client(string $publicId, int $operatorId): ?Client
    {
        return Client::where('public_id', $publicId)->where(fn ($query) => $query
            ->where('operator_id', $operatorId)->orWhereNull('operator_id'))->first();
    }

    private function formalizationClient(CajasV1PendingCredit $credit): Client
    {
        $proposal = $credit->proposal;
        if (isset($proposal['client_id'])) {
            $client = $this->client($proposal['client_id'], $credit->operator_id);
            abort_unless($client, 409, 'El cliente ya no está disponible para este operador.');

            return $client;
        }

        return Client::create([
            'public_id' => (string) Str::ulid(), 'operator_id' => $credit->operator_id,
            'first_name' => $proposal['client']['name'], 'last_name' => '', 'phone' => $proposal['client']['phone'],
        ]);
    }

    public function lock(CajasV1PendingCredit $credit): CajasV1PendingCredit
    {
        // SQLite requires a write before reading the proposal to serialize concurrent approvals.
        DB::table($credit->getTable())->where('id', $credit->id)->update(['id' => DB::raw('id')]);

        return CajasV1PendingCredit::query()->lockForUpdate()->findOrFail($credit->id);
    }

    private function verifyTermsAndFunding(CajasV1PendingCredit $credit): array
    {
        $terms = $credit->proposal['terms'];
        abort_unless($credit->operator->status === 'active' && in_array((int) $terms['term_months'], LoanTerms::active(), true),
            409, 'El operador o el plazo ya no están habilitados.');
        $funding = $credit->funding();
        abort_unless($funding['status'] === 'confirmed', 409, 'El alta requiere fondeo confirmado.');
        $confirmation = $credit->offset ?? $credit->disbursement;
        abort_unless(($confirmation['amount_cents'] ?? null) === $terms['principal_cents']
            && ($confirmation['offset_id'] ?? $confirmation['disbursement_id'] ?? null) === $funding['reference_id'],
            409, 'El fondeo ya no cubre el capital confirmado.');
        $schedule = $this->schedule($terms);
        abort_unless($this->validSchedule($schedule, $terms['principal_cents']), 409, 'Las condiciones no generan un calendario contractual válido.');

        return $schedule;
    }

    private function validSchedule(array $schedule, int $principal): bool
    {
        return array_sum(array_column($schedule, 'principal_cents')) === $principal
            && array_sum(array_column($schedule, 'amount_cents')) <= 999999999999999
            && collect($schedule)->every(fn ($row) => $row['amount_cents'] === $row['principal_cents'] + $row['interest_cents']);
    }

    private function audit(CajasV1PendingCredit $credit, User $actor, string $action, array $data): void
    {
        AuditEvent::create([
            'user_id' => $actor->id, 'action' => 'cajas.v1.pending_credit.'.$action,
            'auditable_type' => CajasV1PendingCredit::class, 'auditable_id' => $credit->id,
            'after' => [
                'pending_credit_id' => $credit->public_id, 'offset_id' => $credit->offset_id,
                'funding_reference_id' => $credit->funding()['reference_id'], ...$data,
            ],
        ]);
    }
}
