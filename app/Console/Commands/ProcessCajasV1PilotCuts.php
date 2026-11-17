<?php

namespace App\Console\Commands;

use App\Domain\Cajas\V1\CutPayments;
use Illuminate\Console\Command;

class ProcessCajasV1PilotCuts extends Command
{
    protected $signature = 'cajas:v1-process-cuts';

    protected $description = 'Aplica los cortes aceptados por la integración Cajas v1 habilitada';

    public function handle(CutPayments $payments): int
    {
        for ($count = 0; $count < 10 && $payments->processNext(); $count++) {
            // Each cut has its own transaction and can be recovered independently.
        }

        return self::SUCCESS;
    }
}
