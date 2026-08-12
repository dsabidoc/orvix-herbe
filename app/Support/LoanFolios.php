<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\Operator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class LoanFolios
{
    public static function next(?int $operatorId = null, string|CarbonInterface|null $date = null, ?int $ignoreLoanId = null): string
    {
        $prefix = self::prefix($operatorId, $date);
        $lastFolio = Loan::query()
            ->where('folio', 'like', $prefix.'%')
            ->when($ignoreLoanId, fn ($query) => $query->whereKeyNot($ignoreLoanId))
            ->orderByDesc('folio')
            ->value('folio');

        $lastSequence = self::sequenceFromFolio($lastFolio);

        return self::format($prefix, $lastSequence + 1);
    }

    public static function prefix(?int $operatorId = null, string|CarbonInterface|null $date = null): string
    {
        $operator = $operatorId ? Operator::query()->find($operatorId) : null;
        $operatorPrefix = preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($operator?->name ?: 'OPE')));
        $operatorPrefix = Str::substr($operatorPrefix ?: 'OPE', 0, 3);
        $purchaseDate = self::date($date)->format('dmY');

        return $operatorPrefix.'-'.$purchaseDate.'-';
    }

    public static function format(string $prefix, int $sequence): string
    {
        return $prefix.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    public static function sequenceFromFolio(?string $folio): int
    {
        if (! $folio || ! str_contains($folio, '-')) {
            return 0;
        }

        $parts = explode('-', $folio);

        return (int) end($parts);
    }

    private static function date(string|CarbonInterface|null $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date);
        }

        return $date ? CarbonImmutable::parse($date, 'America/Merida') : now('America/Merida')->toImmutable();
    }
}
