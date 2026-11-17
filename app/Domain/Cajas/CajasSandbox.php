<?php

namespace App\Domain\Cajas;

class CajasSandbox
{
    public static function enabled(): bool
    {
        return (bool) config('cajas.sandbox_enabled') && app()->environment(['local', 'testing']);
    }

    public static function enforce(): void
    {
        abort_unless(self::enabled(), 403, 'Los movimientos solo están disponibles en el entorno de prueba.');
    }
}
