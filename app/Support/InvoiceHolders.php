<?php

namespace App\Support;

class InvoiceHolders
{
    /**
     * @return array<string, string>
     */
    public static function options(bool $includeAll = false): array
    {
        $options = [
            'Caja' => 'Caja',
            'Recepcion' => 'Recepción',
            'Operador pendiente' => 'Operador pendiente',
            'Operador en tramite' => 'Operador en tramite',
            'Operador abogado' => 'Operador abogado',
            'En tramite' => 'En tramite',
            'Abogado' => 'Abogado',
            'Sin ubicacion' => 'Sin ubicación',
            'Operador en venta' => 'Operador en venta',
        ];

        return $includeAll ? ['' => 'Todas las ubicaciones'] + $options : $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }

    /**
     * @return list<string>
     */
    public static function filterValues(?string $holder): array
    {
        return match ($holder) {
            'Recepcion' => ['Recepcion', 'Recepción'],
            'Operador pendiente' => ['Operador pendiente', 'Operador'],
            'En tramite' => ['En tramite', 'En trámite'],
            'Sin ubicacion' => ['Sin ubicacion', 'Sin ubicación', ''],
            default => filled($holder) ? [$holder] : [],
        };
    }

    public static function label(?string $holder): string
    {
        if (blank($holder)) {
            return 'Sin ubicación';
        }

        $normalized = str($holder)->lower()->ascii()->toString();

        return match ($normalized) {
            'recepcion' => 'Recepción',
            'sin ubicacion' => 'Sin ubicación',
            default => self::options()[$holder] ?? $holder,
        };
    }
}
