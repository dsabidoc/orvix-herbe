<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class LoanFolios
{
    public static function next(?int $operatorId = null, ?CarbonImmutable $date = null): string
    {
        $operator = $operatorId ? Operator::query()->find($operatorId) : null;
        $operatorLetter = Str::upper(Str::substr(Str::ascii($operator?->name ?: 'O'), 0, 1)) ?: 'O';
        $createdOn = ($date ?? now('America/Merida'))->format('dmy');
        $prefix = $operatorLetter.$createdOn;
        $lastFolio = Loan::query()
            ->where('folio', 'like', $prefix.'%')
            ->orderByDesc('folio')
            ->value('folio');

        $lastSequence = $lastFolio ? (int) substr((string) $lastFolio, -2) : 0;

        return $prefix.str_pad((string) ($lastSequence + 1), 2, '0', STR_PAD_LEFT);
    }
}
