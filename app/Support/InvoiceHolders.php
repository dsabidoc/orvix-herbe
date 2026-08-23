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
        if (blank($holder)) {
            return [];
        }

        $normalized = str($holder)->lower()->ascii()->toString();

        return match ($normalized) {
            'caja' => ['Caja', 'caja'],
            'recepcion' => ['Recepcion', 'Recepción', 'recepcion'],
            'operador pendiente' => ['Operador pendiente', 'Operador'],
            'operador en tramite' => ['Operador en tramite', 'Operador en trámite'],
            'operador abogado' => ['Operador abogado'],
            'en tramite' => ['En tramite', 'En trámite'],
            'abogado' => ['Abogado'],
            'sin ubicacion' => ['Sin ubicacion', 'Sin ubicación', ''],
            'operador en venta' => ['Operador en venta'],
            default => [$holder],
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
