<?php

namespace App\Http\Controllers;

use App\Domain\Cajas\V1\CutApproval;
use App\Domain\Cajas\V1\CutPayments;
use App\Domain\Cajas\V1\CutPayouts;
use App\Domain\Cajas\V1\DirectCustody;
use App\Domain\Cajas\V1\DirectFunding;
use App\Domain\Cajas\V1\OrdinaryPayment;
use App\Domain\Cajas\V1\PaymentCorrection;
use App\Domain\Cajas\V1\PendingCredits;
use App\Domain\Cajas\V1\IntegrationAccess;
use App\Models\CajasV1PendingCredit;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Operator;
use App\Support\LoanTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;

class CajasV1PilotController extends Controller
{
    private const ID = 'regex:/\A[A-Za-z0-9_-]{1,80}\z/';

    public function __construct(private IntegrationAccess $access, private CutPayments $payments) {}

    public function catalog(Request $request, OrdinaryPayment $ordinary): JsonResponse
    {
        $this->access->authenticate($request, 'read');
        $input = $this->validated($request->query(), [
            'operator' => ['required', self::ID], 'cut_on' => 'required|date_format:Y-m-d',
            'folio' => 'sometimes|string|max:80', 'model' => 'sometimes|string|max:120',
            'day' => 'sometimes|integer|between:1,31', 'page' => 'sometimes|integer|between:1,10000',
            'installment_id' => ['sometimes', self::ID],
        ]);
        $operator = $this->operator($input['operator'], readOnly: true);
        $exact = $input['installment_id'] ?? null;
        $query = Installment::with(['loan.client', 'loan.vehicle', 'allocations.movement'])
            ->whereHas('loan', fn ($loan) => $loan->where('operator_id', $operator->id)
                ->when(isset($input['folio']), fn ($q) => $q->where('folio', mb_strtoupper($input['folio'])))
                ->when(isset($input['day']), fn ($q) => $q->where('payment_day', $input['day']))
                ->when(isset($input['model']), fn ($q) => $q->whereHas('vehicle',
                    fn ($vehicle) => $vehicle->whereRaw('LOWER(model) = ?', [mb_strtolower($input['model'])]))))
            ->when($exact, fn ($q) => $q->where('public_id', $exact))
            ->orderBy('due_date')->orderBy('id');
        $rows = [];
        $advanceable = [];
        $offset = ((int) ($input['page'] ?? 1) - 1) * 50;
        if ($exact || $this->access->usesCajas($input['operator'], $input['cut_on'])) {
            foreach ($query->when(! $exact, fn ($q) => $q->where('remaining_amount', '>', 0))->lazy(150) as $letter) {
                $row = $ordinary->catalogRow($letter, $input['cut_on']);
                if (! $this->access->usesCajas($input['operator'], $input['cut_on'])) {
                    $row['allowed_payment_types'] = [];
                }
                if (in_array('capital', $row['allowed_payment_types'], true)) {
                    $advanceable[$letter->loan_id] ??= $ordinary->advanceableTail($letter->loan, $input['cut_on']);
                    if (isset($advanceable[$letter->loan_id][$letter->id])) {
                        $row['allowed_payment_types'][] = 'general_capital';
                    }
                }
                if (($exact || $row['allowed_payment_types'] !== []) && $offset-- <= 0) {
                    $rows[] = $row;
                    if (count($rows) === 51) {
                        break;
                    }
                }
            }
        }
        if ($exact && $rows === [] && $offset === 0) {
            $old = DB::table('cajas_v1_installment_replacements')->where('public_id', $exact)->first();
            if ($old && Loan::where('public_id', $old->loan_public_id)->where('operator_id', $operator->id)->exists()) {
                $rows[] = [
                    'loan_id' => $old->loan_public_id, 'installment_id' => $exact, 'status' => 'replaced',
                    'version' => hash('sha256', 'replaced:'.$exact), 'allowed_payment_types' => [],
                    'replacement_installment_ids' => $this->payments->replacementIds($old->replacement_public_id, $old->loan_public_id),
                ];
            }
        }

        return response()->json([
            'currency' => 'MXN', 'cut_on' => $input['cut_on'], 'data' => array_slice($rows, 0, 50),
            'has_more' => count($rows) > 50,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function validateCut(Request $request): JsonResponse
    {
        $this->access->authenticate($request, 'read');
        $cut = $this->cut($this->body($request));
        $items = array_column($this->payments->preview($cut), 'result');

        return response()->json([...$this->identity($cut), 'currency' => 'MXN', 'status' => 'valid', 'items' => $items]);
    }

    public function receive(Request $request, CutApproval $approval, CutPayouts $payouts): JsonResponse
    {
        $this->access->authenticate($request, 'write');
        $body = $this->body($request);
        $identity = $this->validated($body, [
            'cut_id' => ['required', self::ID], 'version' => 'required|integer|between:1,2147483647',
            'operator' => ['required', self::ID],
        ]);
        $this->access->authorizeOperator($identity['operator']);
        if ($request->header('Idempotency-Key') !== 'cut:'.$identity['cut_id'].':v'.$identity['version']) {
            $this->access->fail(422, 'INVALID_IDEMPOTENCY_KEY', 'La clave debe identificar el corte y su versión.');
        }
        $hash = hash('sha256', $request->getContent());
        if ($this->registered($identity, $hash)) {
            return $this->acknowledge($identity);
        }

        $this->access->requireAdmission();
        $cut = $this->cut($body, submission: true);
        $this->authorizeCutAccounts($request->getContent());
        $approval->verify($cut, $hash, (string) $request->header('Approval-Proof'));
        DB::transaction(function () use ($request, $cut, $hash, $payouts): void {
            // SQLite serializes writers across the isolated database.
            DB::table('operators')->where('public_id', $cut['operator'])->update(['id' => DB::raw('id')]);
            if ($this->registered($cut, $hash)) {
                return;
            }
            $latest = DB::table('cajas_v1_cut_executions')->where('cut_id', $cut['cut_id'])->orderByDesc('version')->first();
            if ($latest) {
                $this->access->authorizeOperator($latest->operator);
                if ($latest->operator !== $cut['operator']) {
                    $this->access->fail(409, 'CUT_OPERATOR_CHANGED', 'El corte pertenece a otro operador.');
                }
                if ($latest->status === 'applied') {
                    $this->access->fail(409, 'CUT_ALREADY_APPLIED', 'El corte ya se aplicó.');
                }
                if (in_array($latest->status, ['received', 'processing'], true)) {
                    $this->access->fail(409, 'CUT_IN_PROGRESS', 'Ya hay una versión del corte en aplicación.');
                }
                if ((int) $cut['version'] < $latest->version) {
                    $this->access->fail(409, 'CUT_VERSION_STALE', 'La versión es anterior a la última registrada.');
                }
            }
            $payouts->remember($cut);
            DB::table('cajas_v1_cut_executions')->insert([
                ...$this->identity($cut), 'operator' => $cut['operator'], 'status' => 'received',
                'body_hash' => $hash, 'body' => $request->getContent(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);

        return $this->acknowledge($cut);
    }

    public function result(Request $request, string $cut, int $version): JsonResponse
    {
        $this->access->authenticate($request, 'read');
        $record = DB::table('cajas_v1_cut_executions')->where('cut_id', $cut)->where('version', $version)->first();
        if (! $record) {
            $this->access->fail(404, 'CUT_VERSION_NOT_FOUND', 'La versión indicada todavía no está registrada.');
        }
        $this->access->authorizeOperator($record->operator, readOnly: true);
        $this->authorizeCutAccounts($record->body);

        return response()->json([
            'cut_id' => $cut, 'version' => $version, 'currency' => 'MXN', 'status' => $record->status,
            ...($record->result ? json_decode($record->result, true, flags: JSON_THROW_ON_ERROR) : []),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function pendingCredit(Request $request, string $pendingCredit, PendingCredits $credits): JsonResponse
    {
        $this->access->authenticate($request, 'read');

        return response()->json($credits->result($this->credit($pendingCredit, readOnly: true)))->header('Cache-Control', 'private, no-store');
    }

    public function correctPayment(Request $request, string $cut, PaymentCorrection $corrections): JsonResponse
    {
        $this->access->authenticate($request, 'write');
        $body = $this->body($request);
        $identity = $this->validated($body, ['correction_id' => ['required', self::ID], 'operator' => ['required', self::ID]]);
        $this->access->authorizeOperator($identity['operator']);
        if ($request->header('Idempotency-Key') !== 'correction:'.$identity['correction_id']) {
            $this->access->fail(422, 'INVALID_IDEMPOTENCY_KEY', 'La clave debe identificar la corrección.');
        }
        $hash = hash('sha256', $request->getContent());
        if (($recorded = $corrections->replay($cut, $identity['correction_id'], $hash)) !== null) {
            return $recorded->header('Cache-Control', 'private, no-store');
        }

        $this->access->requireAdmission();
        $integer = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value)) {
                $fail('El valor debe ser un entero JSON.');
            }
        };
        $cents = ['required', 'integer', 'between:1,999999999999999', $integer];
        $body = $this->validated($body, [
            'correction_id' => ['required', self::ID], 'cut_version' => ['required', 'integer', 'between:1,2147483647', $integer],
            'item_id' => ['required', self::ID], 'operator' => ['required', self::ID],
            'payment_id' => ['required', self::ID], 'payment_folio' => 'required|string|max:80',
            'currency' => 'required|in:MXN', 'before_cents' => $cents, 'after_cents' => [...$cents, 'lt:before_cents'],
            'reason' => 'required|string|max:1000', 'prepared_by' => ['required', self::ID],
            'approval' => 'required|array', 'approval.id' => ['required', self::ID],
            'approval.approved_by' => ['required', self::ID], 'approval.approved_at' => ['required', 'date', 'regex:/Z\z/'],
        ]);

        return $corrections->apply($cut, $body, $request->getContent(), (string) $request->header('Approval-Proof'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function fundPendingCredit(Request $request, string $pendingCredit, DirectFunding $funding): JsonResponse
    {
        $this->access->authenticate($request, 'write');
        $body = $this->body($request);
        $identity = $this->validated($body, ['operator' => ['required', self::ID], 'event_id' => ['required', self::ID]]);
        $this->access->authorizeOperator($identity['operator']);
        $credit = $this->credit($pendingCredit);
        if ($credit->operator->public_id !== $identity['operator']) {
            $this->access->fail(409, 'FUNDING_CONFLICT', 'El crédito corresponde a otro operador.');
        }
        if ($request->header('Idempotency-Key') !== 'funding:'.$identity['event_id']) {
            $this->access->fail(422, 'INVALID_IDEMPOTENCY_KEY', 'La clave debe identificar el evento de fondeo.');
        }

        return response()->json($funding->apply($credit, $body, hash('sha256', $request->getContent()), (string) $request->header('Funding-Proof')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function directPayment(Request $request, string $payment, DirectCustody $custody): JsonResponse
    {
        $this->access->authenticate($request, 'read');
        $this->validated($request->query(), ['currency' => 'required|in:MXN']);

        return response()->json($custody->payment($payment))->header('Cache-Control', 'private, no-store');
    }

    public function confirmCustody(Request $request, DirectCustody $custody): JsonResponse
    {
        $this->access->authenticate($request, 'write');
        $body = $this->body($request);
        $id = ['required', self::ID];
        $identity = $this->validated($body, ['cash_movement_id' => $id, 'cash_account_id' => $id]);
        $account = $identity['cash_account_id'];
        $movement = $identity['cash_movement_id'];
        $this->access->authorizeCustodyAccount($account);
        if ($request->header('Idempotency-Key') !== 'custody:'.$movement) {
            $this->access->fail(422, 'INVALID_IDEMPOTENCY_KEY', 'La clave debe identificar el movimiento de custodia.');
        }
        $raw = $request->getContent();
        $hash = hash('sha256', $raw);
        if (($previous = $custody->replay($movement, $account, $hash)) !== null) {
            return response()->json($previous)->header('Cache-Control', 'private, no-store');
        }
        $this->access->requireAdmission();
        $data = $this->validated($body, [
            'cash_movement_id' => $id, 'cash_account_id' => $id, 'currency' => 'required|in:MXN',
            'kind' => 'required|in:direct_payment_receipt,correction_refund,payout_return_transfer',
            'amount_cents' => ['required', 'integer', 'between:1,999999999999999', function ($attribute, $value, $fail): void {
                if (! is_int($value)) {
                    $fail('El importe debe ser un entero JSON en centavos.');
                }
            }],
            'effective_on' => 'required|date_format:Y-m-d', 'confirmed_by' => $id,
            'confirmed_at' => ['required', 'date', 'regex:/Z\z/'], 'evidence_ref' => 'required|string|max:255',
            'payment_id' => ['required_if:kind,direct_payment_receipt', 'prohibited_unless:kind,direct_payment_receipt', self::ID],
            'payment_folio' => 'required_if:kind,direct_payment_receipt|prohibited_unless:kind,direct_payment_receipt|string|max:80',
            'correction_id' => ['required_if:kind,correction_refund', 'prohibited_unless:kind,correction_refund', self::ID],
            'receipt_id' => ['required_if:kind,correction_refund', 'prohibited_unless:kind,correction_refund', self::ID],
            'source_cash_account_id' => ['required_if:kind,payout_return_transfer', 'prohibited_unless:kind,payout_return_transfer', 'different:cash_account_id', self::ID],
            'payout_return_id' => ['required_if:kind,payout_return_transfer', 'prohibited_unless:kind,payout_return_transfer', 'different:cash_movement_id', self::ID],
        ]);

        return response()->json($custody->confirm($data, $raw, $hash, (string) $request->header('Custody-Proof')))
            ->header('Cache-Control', 'private, no-store');
    }

    private function credit(string $pendingCredit, bool $readOnly = false): CajasV1PendingCredit
    {
        $credit = CajasV1PendingCredit::with('operator', 'loan')->where('public_id', $pendingCredit)->first();
        if (! $credit) {
            $this->access->fail(404, 'PENDING_CREDIT_NOT_FOUND', 'El crédito pendiente todavía no está registrado.');
        }
        $this->access->authorizeOperator($credit->operator->public_id, readOnly: $readOnly);

        return $credit;
    }

    private function registered(array $cut, string $hash): bool
    {
        $record = DB::table('cajas_v1_cut_executions')->where($this->identity($cut))->first();
        if (! $record) {
            return false;
        }
        $this->access->authorizeOperator($record->operator);
        $this->authorizeCutAccounts($record->body);
        if (! hash_equals($record->body_hash, $hash)) {
            $this->access->fail(409, 'IDEMPOTENCY_KEY_REUSED', 'La clave ya está registrada con otro contenido.');
        }

        return true;
    }

    private function acknowledge(array $cut): JsonResponse
    {
        return response()->json([...$this->identity($cut), 'status' => 'received'], 202)
            ->header('Location', '/api/v1/cajas/cortes/'.$cut['cut_id'].'/versiones/'.$cut['version']);
    }

    private function authorizeCutAccounts(string $body): void
    {
        if (! $this->access->production()) {
            return;
        }
        $cut = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        foreach (['receipts', 'payouts', 'payout_returns', 'disbursements'] as $group) {
            foreach ($cut[$group] ?? [] as $entry) {
                $this->access->authorizeCustodyAccount($entry['cash_account_id']);
            }
        }
    }

    private function identity(array $cut): array
    {
        return ['cut_id' => $cut['cut_id'], 'version' => (int) $cut['version']];
    }

    private function operator(string $publicId, bool $readOnly = false): Operator
    {
        $this->access->authorizeOperator($publicId, readOnly: $readOnly);
        $operator = Operator::where('public_id', $publicId)->where('status', 'active')->first();
        if (! $operator) {
            $this->access->fail(404, 'OPERATOR_NOT_FOUND', 'El operador no está disponible.');
        }

        return $operator;
    }

    private function cut(array $body, bool $submission = false): array
    {
        $jsonInteger = function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('El importe debe enviarse como entero JSON en centavos.');
            }
        };
        $money = ['required', 'integer', 'between:1,999999999999999', $jsonInteger];
        $nonnegative = ['required', 'integer', 'between:0,999999999999999', $jsonInteger];
        $zero = ['required', 'integer', 'in:0', $jsonInteger];
        $rules = [
            'cut_id' => ['required', self::ID], 'version' => 'required|integer|between:1,2147483647',
            'operator' => ['required', self::ID], 'cut_on' => 'required|date_format:Y-m-d',
            'currency' => 'required|in:MXN', 'items' => 'present|array|list|max:100',
            'items.*.item_id' => ['required', self::ID, 'distinct:strict'],
            'items.*.installment_id' => ['required_unless:items.*.payment_type,general_capital', 'prohibited_if:items.*.payment_type,general_capital', self::ID],
            'items.*.installment_version' => 'required_unless:items.*.payment_type,general_capital|prohibited_if:items.*.payment_type,general_capital|string|max:100',
            'items.*.loan_id' => ['required_if:items.*.payment_type,general_capital', self::ID],
            'items.*.installments' => 'required_if:items.*.payment_type,general_capital|prohibited_unless:items.*.payment_type,general_capital|array|list|min:1',
            'items.*.installments.*.installment_id' => ['required', self::ID],
            'items.*.installments.*.installment_version' => 'required|string|max:100',
            'items.*.installments.*.principal_cents' => $money,
            'items.*.payment_type' => 'required|string|max:40',
            'items.*.contract_amount_cents' => $money, 'items.*.reported_amount_cents' => $money,
            'items.*.extras' => 'present|array|list',
        ];
        if ($submission) {
            $rules += [
                'prepared_by' => ['required', self::ID], 'approval' => 'required|array',
                'approval.id' => ['required', self::ID], 'approval.approved_by' => ['required', self::ID],
                'approval.approved_at' => ['required', 'date', 'regex:/Z\z/'],
                'totals' => 'required|array', 'totals.reported_cents' => $nonnegative, 'totals.received_cents' => $nonnegative,
                'totals.offset_cents' => $nonnegative, 'totals.paid_cents' => $nonnegative,
                'totals.remaining_cents' => ['required', 'integer', 'between:-999999999999999,999999999999999', $jsonInteger],
                'receipts' => 'present|array|list|max:100',
                'receipts.*.receipt_id' => ['required', self::ID, 'distinct:strict'],
                'receipts.*.cash_account_id' => ['required', self::ID],
                'receipts.*.amount_cents' => $money, 'receipts.*.received_on' => 'required|date_format:Y-m-d|after_or_equal:cut_on',
                'offsets' => 'present|array|list|max:100',
                'offsets.*.offset_id' => ['required', self::ID, 'distinct:strict'],
                'offsets.*.pending_credit_id' => ['required', self::ID, 'distinct:strict'],
                'offsets.*.amount_cents' => $money,
                'offsets.*.executed_on' => 'required|date_format:Y-m-d|after_or_equal:cut_on',
                'offsets.*.confirmation_id' => ['required', self::ID, 'distinct:strict'],
                'offsets.*.confirmed_by' => ['required', self::ID],
                'offsets.*.confirmed_at' => ['required', 'date', 'regex:/Z\z/'],
                'offsets.*.evidence_ref' => 'required|string|max:255',
                'payouts' => 'present|array|list|max:100',
                'payouts.*' => 'required|array:payout_id,cash_account_id,amount_cents,paid_on',
                'payouts.*.payout_id' => ['required', 'string', self::ID, 'distinct:strict'],
                'payouts.*.cash_account_id' => ['required', 'string', self::ID],
                'payouts.*.amount_cents' => $money,
                'payouts.*.paid_on' => 'required|date_format:Y-m-d|after_or_equal:cut_on',
                'payout_returns' => 'sometimes|array|list|max:100',
                'payout_returns.*' => 'required|array:payout_return_id,payout_id,cash_account_id,amount_cents,received_on,confirmed_by,confirmed_at,evidence_ref',
                'payout_returns.*.payout_return_id' => ['required', 'string', self::ID, 'distinct:strict'],
                'payout_returns.*.payout_id' => ['required', 'string', self::ID],
                'payout_returns.*.cash_account_id' => ['required', 'string', self::ID],
                'payout_returns.*.amount_cents' => $money,
                'payout_returns.*.received_on' => 'required|date_format:Y-m-d',
                'payout_returns.*.confirmed_by' => ['required', 'string', self::ID],
                'payout_returns.*.confirmed_at' => ['required', 'date', 'regex:/Z\z/', 'before_or_equal:approval.approved_at'],
                'payout_returns.*.evidence_ref' => 'required|string|max:255',
                'disbursements' => 'present|array|list|max:100',
                'disbursements.*.disbursement_id' => ['required', self::ID, 'distinct:strict'],
                'disbursements.*.pending_credit_id' => ['required', self::ID, 'distinct:strict'],
                'disbursements.*.cash_account_id' => ['required', self::ID],
                'disbursements.*.amount_cents' => $money,
                'disbursements.*.disbursed_on' => 'required|date_format:Y-m-d|after_or_equal:cut_on',
                'disbursements.*.recipient' => 'required|array:type,id',
                'disbursements.*.recipient.type' => 'required|in:client,operator',
                'disbursements.*.recipient.id' => ['required', self::ID],
                'disbursements.*.confirmation_id' => ['required', self::ID, 'distinct:strict'],
                'disbursements.*.confirmed_by' => ['required', self::ID],
                'disbursements.*.confirmed_at' => ['required', 'date', 'regex:/Z\z/'],
                'disbursements.*.evidence_ref' => 'required|string|max:255',
                'pending_credits' => 'present|array|list|max:100',
                'pending_credits.*.pending_credit_id' => ['required', self::ID, 'distinct:strict'],
                'pending_credits.*.client_id' => ['required_without:pending_credits.*.client', 'prohibits:pending_credits.*.client', self::ID],
                'pending_credits.*.client' => 'required_without:pending_credits.*.client_id|array',
                'pending_credits.*.client.name' => 'required_with:pending_credits.*.client|string|max:150',
                'pending_credits.*.client.phone' => ['required_with:pending_credits.*.client', 'string', 'regex:/\A\d{10}\z/'],
                'pending_credits.*.terms' => 'required|array',
                'pending_credits.*.terms.principal_cents' => $money,
                'pending_credits.*.terms.monthly_rate_percent' => ['required', 'string', 'regex:/\A\d{1,4}(?:\.\d{1,4})?\z/'],
                'pending_credits.*.terms.term_months' => 'required|integer|min:1',
                'pending_credits.*.terms.start_on' => 'required|date_format:Y-m-d',
                'pending_credits.*.terms.first_payment_on' => 'required|date_format:Y-m-d|after_or_equal:pending_credits.*.terms.start_on',
                'pending_credits.*.terms.payment_day' => 'required|integer|between:1,31',
                'pending_credits.*.terms.calculation_method' => 'required|in:regular',
                'pending_credits.*.terms.interest_calculation_method' => 'required|in:fixed_principal',
                'pending_credits.*.terms.administration_fee_cents' => $zero,
                'pending_credits.*.terms.vat_enabled' => ['present', function ($attribute, $value, $fail): void {
                    if ($value !== false) {
                        $fail('El alta de este piloto requiere IVA desactivado.');
                    }
                }],
                'pending_credits.*.terms.rounding_option' => 'required|in:tens,hundreds',
                'pending_credits.*.terms.rounding_adjustment' => 'required|in:first',
                'pending_credits.*.proposed_schedule' => 'present|array|list|max:120',
                'pending_credits.*.proposed_schedule.*.number' => 'required|integer|min:1',
                'pending_credits.*.proposed_schedule.*.due_on' => 'required|date_format:Y-m-d',
                'pending_credits.*.proposed_schedule.*.principal_cents' => $nonnegative,
                'pending_credits.*.proposed_schedule.*.interest_cents' => $nonnegative,
                'pending_credits.*.proposed_schedule.*.administration_fee_cents' => $nonnegative,
                'pending_credits.*.proposed_schedule.*.vat_cents' => $nonnegative,
                'pending_credits.*.proposed_schedule.*.amount_cents' => $nonnegative,
                'pending_credits.*.funding' => 'required|array',
                'pending_credits.*.funding.type' => 'required|in:offset,disbursement,unfunded',
                'pending_credits.*.funding.reference_id' => [
                    'required_unless:pending_credits.*.funding.type,unfunded',
                    'prohibited_if:pending_credits.*.funding.type,unfunded', self::ID, 'distinct:strict',
                ],
            ];
        }
        $cut = $this->validated($body, $rules);
        $this->operator($cut['operator']);
        if (! $this->access->usesCajas($cut['operator'], $cut['cut_on'])) {
            $this->access->fail(422, 'CAPTURE_CHANNEL_MISMATCH', 'La fecha y el operador no están asignados a Cajas.');
        }
        foreach ($cut['items'] as $item) {
            $this->payments->validateItemChannel($item);
            if (! in_array($item['payment_type'], ['ordinary', 'capital', 'capital_interest', 'general_capital'], true)) {
                $this->access->fail(422, 'PAYMENT_NOT_SUPPORTED', 'El tipo de pago no está habilitado en el piloto.');
            }
        }
        if ($submission) {
            if ($cut['items'] === [] && $cut['pending_credits'] === []) {
                $this->access->fail(422, 'INVALID_REQUEST', 'El corte debe contener un cobro o una propuesta.');
            }
            $this->payments->reconcile($cut, 422);
            if ($cut['pending_credits'] !== []) {
                $terms = LoanTerms::active();
                foreach ($cut['pending_credits'] as $proposal) {
                    if (! in_array((int) $proposal['terms']['term_months'], $terms, true)) {
                        $this->access->fail(422, 'CREDIT_TERMS_INVALID', 'El plazo del crédito no está habilitado.', [
                            ['pending_credit_id' => $proposal['pending_credit_id']],
                        ]);
                    }
                }
            }
        }

        return $cut;
    }

    private function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }
        if (! is_array($body) || array_is_list($body)) {
            $this->access->fail(422, 'INVALID_REQUEST', 'Se requiere un objeto JSON.');
        }

        return $body;
    }

    private function validated(array $body, array $rules): array
    {
        $validator = Validator::make($body, $rules);
        if ($validator->fails()) {
            $this->access->fail(422, 'INVALID_REQUEST', 'Hay campos no válidos.', $validator->errors()->toArray());
        }

        return $validator->validated();
    }
}
