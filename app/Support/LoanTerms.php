<?php

namespace App\Support;

use App\Models\LoanTermOption;
use Illuminate\Support\Facades\Schema;

class LoanTerms
{
    /**
     * @return list<int>
     */
    public static function defaults(): array
    {
        return [6, 12, 18, 24, 30, 36, 40, 48];
    }

    /**
     * @return list<int>
     */
    public static function active(): array
    {
        if (! Schema::hasTable('loan_term_options')) {
            return self::defaults();
        }

        $terms = LoanTermOption::query()
            ->where('is_active', true)
            ->orderBy('term_months')
            ->pluck('term_months')
            ->map(fn ($term) => (int) $term)
            ->values()
            ->all();

        return $terms ?: self::defaults();
    }

    /**
     * @return list<int>
     */
    public static function allowed(): array
    {
        return self::active();
    }
}
