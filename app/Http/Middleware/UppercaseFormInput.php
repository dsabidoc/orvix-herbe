<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UppercaseFormInput
{
    /**
     * Fields that must keep their original value because they are credentials,
     * technical identifiers, enums, dates, files, or numeric inputs.
     */
    private const EXCLUDED_KEYS = [
        '_method',
        '_token',
        'administration_fee',
        'affects_investors',
        'action',
        'calculation_method',
        'client_id',
        'create_user',
        'current_password',
        'delinquency_grace_days',
        'delinquency_rate',
        'disbursement_delivered_on',
        'email',
        'fecha',
        'first_payment_date',
        'generic_password',
        'holder',
        'include_generated_interest',
        'include_returned_capital',
        'interest_calculation_method',
        'interest_share_percent',
        'investor_id',
        'invoice_file',
        'invoice_holder',
        'invoice_mime_type',
        'invoice_original_name',
        'invoice_size',
        'invoice_temp_path',
        'new_password',
        'opening_fee_type',
        'opening_fee_value',
        'operator_id',
        'password',
        'password_confirmation',
        'payment_day',
        'payment_effect',
        'payment_method',
        'permission',
        'permissions',
        'phone',
        'rate_type',
        'rate_value',
        'remember',
        'return_to',
        'role',
        'selected_option',
        'settlement_reason',
        'start_date',
        'status',
        'term_months',
        'to_holder',
        'token',
        'user_id',
        'vat_enabled',
        'year',
    ];

    private const EXCLUDED_SUFFIXES = [
        '_at',
        '_date',
        '_day',
        '_fee',
        '_file',
        '_id',
        '_ids',
        '_month',
        '_months',
        '_on',
        '_percent',
        '_percentage',
        '_rate',
        '_token',
        '_type',
        '_url',
        '_value',
    ];

    private const EXCLUDED_CONTAINS = [
        'amount',
        'capital',
        'correo',
        'date',
        'dia',
        'dias',
        'email',
        'fecha',
        'file',
        'folio',
        'idempotency',
        'interest',
        'interes',
        'iva',
        'method',
        'monto',
        'password',
        'path',
        'porcentaje',
        'rate',
        'token',
        'url',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $request->request->replace($this->uppercase(
            $request->request->all(),
            excludeName: $request->routeIs('settings.roles.*', 'settings.permissions.*'),
        ));

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $path
     * @return array<string, mixed>
     */
    private function uppercase(array $input, array $path = [], bool $excludeName = false): array
    {
        foreach ($input as $key => $value) {
            $currentPath = [...$path, (string) $key];

            if (is_array($value)) {
                $input[$key] = $this->uppercase($value, $currentPath, $excludeName);

                continue;
            }

            if (is_string($value) && $this->shouldUppercase($currentPath, $excludeName)) {
                $input[$key] = Str::upper($value);
            }
        }

        return $input;
    }

    /**
     * @param  array<int, string>  $path
     */
    private function shouldUppercase(array $path, bool $excludeName = false): bool
    {
        $key = Str::lower((string) end($path));
        $normalizedPath = array_map(fn (string $segment): string => Str::lower($segment), $path);

        if ($excludeName && $key === 'name') {
            return false;
        }

        foreach ($normalizedPath as $pathKey) {
            if (in_array($pathKey, self::EXCLUDED_KEYS, true)) {
                return false;
            }

            foreach (self::EXCLUDED_SUFFIXES as $suffix) {
                if (Str::endsWith($pathKey, $suffix)) {
                    return false;
                }
            }

            foreach (self::EXCLUDED_CONTAINS as $needle) {
                if (Str::contains($pathKey, $needle)) {
                    return false;
                }
            }
        }

        return true;
    }
}
