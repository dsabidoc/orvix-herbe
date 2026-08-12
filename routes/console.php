<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\Loan;
use App\Support\LoanFolios;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orvix:mail-test {email}', function (string $email) {
    Mail::raw('Correo de diagnostico de Orvix Prestamos. Si recibiste este mensaje, SMTP funciona.', function ($message) use ($email) {
        $message->to($email)->subject('Diagnostico SMTP Orvix Prestamos');
    });

    $this->info('Correo de diagnostico enviado sin exponer credenciales.');
})->purpose('Send a branded-safe SMTP diagnostic email');

Artisan::command('orvix:rebuild-loan-folios {--dry-run}', function () {
    $loans = Loan::query()
        ->orderBy('start_date')
        ->orderBy('id')
        ->get();

    $sequences = [];
    $updates = $loans->map(function (Loan $loan) use (&$sequences) {
        $prefix = LoanFolios::prefix($loan->operator_id, $loan->start_date);
        $sequences[$prefix] = ($sequences[$prefix] ?? 0) + 1;

        return [
            'id' => $loan->id,
            'old' => $loan->folio,
            'new' => LoanFolios::format($prefix, $sequences[$prefix]),
        ];
    });

    $changed = $updates->filter(fn (array $row) => $row['old'] !== $row['new'])->values();

    if ($changed->isEmpty()) {
        $this->info('Todos los folios de prestamos ya cumplen el formato actual.');

        return 0;
    }

    $this->table(['ID', 'Folio actual', 'Folio nuevo'], $changed->map(fn (array $row) => [
        $row['id'],
        $row['old'],
        $row['new'],
    ])->all());

    if ($this->option('dry-run')) {
        $this->warn($changed->count().' folio(s) cambiarian. No se guardo ningun cambio por --dry-run.');

        return 0;
    }

    DB::transaction(function () use ($changed) {
        foreach ($changed as $row) {
            Loan::query()
                ->whereKey($row['id'])
                ->update(['folio' => 'TMP-'.$row['id'].'-'.substr(sha1($row['old'].'|'.$row['new']), 0, 10)]);
        }

        foreach ($changed as $row) {
            Loan::query()
                ->whereKey($row['id'])
                ->update(['folio' => $row['new']]);
        }
    });

    $this->info($changed->count().' folio(s) de prestamos actualizados.');

    return 0;
})->purpose('Rebuild loan folios using operator initial, purchase date and sequence');
