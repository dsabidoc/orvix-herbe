<?php

namespace App\Domain\Cajas;

class CajasPermissions
{
    // Default profiles keep stage-2 capabilities. Stage-3 actions require explicit grants.
    public const READ = [
        'cajas.access',
        'cajas.accounts.view',
        'cajas.collections.loans.view',
    ];

    public const MANAGE = [
        'cajas.accounts.manage',
        'cajas.access.manage',
    ];

    public const SOURCES = ['cajas.collections.naboo.view'];
}
