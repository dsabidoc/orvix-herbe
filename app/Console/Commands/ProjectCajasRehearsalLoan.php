<?php

namespace App\Console\Commands;

use App\Domain\Cajas\CashLoanRehearsal;
use App\Models\Loan;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProjectCajasRehearsalLoan extends Command
{
    protected $signature = 'cajas:ensayo-proyectar {folio : Folio exacto en la base aislada}';

    protected $description = 'Habilita un préstamo seleccionado en el catálogo de Cajas de ensayo';

    public function handle(CashLoanRehearsal $rehearsal): int
    {
        try {
            $rehearsal->enforce();
            $loan = Loan::where('folio', $this->argument('folio'))->first();
            if (! $loan) {
                $this->error('Folio no encontrado en la copia.');

                return self::FAILURE;
            }
            $rehearsal->project($loan);
        } catch (HttpExceptionInterface $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Préstamo disponible para el operador asignado en Cajas de ensayo.');

        return self::SUCCESS;
    }
}
