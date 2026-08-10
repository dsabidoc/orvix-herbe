<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orvix:mail-test {email}', function (string $email) {
    Mail::raw('Correo de diagnostico de Orvix Prestamos. Si recibiste este mensaje, SMTP funciona.', function ($message) use ($email) {
        $message->to($email)->subject('Diagnostico SMTP Orvix Prestamos');
    });

    $this->info('Correo de diagnostico enviado sin exponer credenciales.');
})->purpose('Send a branded-safe SMTP diagnostic email');
