<?php

namespace App\Console\Commands;

use App\Domain\Cajas\V1\CustodyLedger;
use App\Domain\Cajas\V1\PilotAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class RecordCajasV1Custody extends Command
{
    protected $signature = 'cajas:v1-custody {action : opening, recognize, observe o close} {file : Archivo JSON con la evidencia} {--actor= : Correo del usuario financiero que reconoce el registro}';

    protected $description = 'Registra evidencia auxiliar de custodia en la copia local del piloto Cajas v1';

    public function handle(PilotAccess $access, CustodyLedger $ledger): int
    {
        if (! $access->enabled()) {
            $this->error('La copia local del piloto no está habilitada.');

            return self::FAILURE;
        }

        try {
            $file = $this->argument('file');
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('El archivo de evidencia no está disponible.');
            }
            $input = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $actor = User::where('email', $this->option('actor'))->first();
            if (! $actor || ! is_array($input) || array_is_list($input)) {
                throw new RuntimeException('Indica un usuario de auditoría y un objeto JSON de evidencia.');
            }
            $result = $ledger->record($this->argument('action'), $input, $actor);
        } catch (HttpResponseException|JsonException|RuntimeException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
