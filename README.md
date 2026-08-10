# Orvix Prestamos

Sistema Laravel para administrar prestamos vehiculares, cobranza por operador, cortes semanales, expedientes, pagares, liquidaciones e inversionistas.

## Estado actual

Primera entrega tecnica:

- Laravel 13.8 / PHP 8.4+ con Blade, Livewire 4, Tailwind 4 y Vite.
- `spatie/laravel-permission` configurado con roles y permisos iniciales.
- Modelo de datos base para cartera, cobranza, cortes, ledger, documentos, pagares, liquidaciones, inversionistas y auditoria.
- Motor determinista de simulacion de prestamos en `App\Domain\Loans\LoanScheduleCalculator`.
- Datos demo realistas derivados de la minuta/transcripcion: Samuel, Dario, Santiago, Adriana, cartera directa e inversionista BM de Victor.
- Login, panel, cartera por rol, detalle de prestamo, registro de cobros por confirmar, aplicacion administrativa de pagos y cortes semanales.
- Modulo `Cobranza` mensual: cada operador ve solo sus letras, marca `Pagado` y eso alimenta el corte semanal.
- Corte imprimible con encabezado de operador/semana, pagos marcados y atrasados sin marcar.
- Letras no marcadas se arrastran al corte de la semana siguiente como atrasadas.
- Pruebas automatizadas del ejemplo financiero, login, aislamiento de operador y confirmacion de pagos.
- Panel responsivo inspirado en el kit Finstack.

## Accesos demo

Todos usan la contrasena `orvix-demo`.

- Administrador: `admin@orvix.test`
- Samuel: `samuel@orvix.test`
- Dario: `dario@orvix.test`
- Santiago: `santiago@orvix.test`
- Adriana: `adriana@orvix.test`
- Inversionista: `victor@orvix.test`

Estos usuarios son solo para desarrollo/local; no deben existir en produccion.

## Instalacion local

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Para pruebas:

```bash
php artisan test
npm run build
./vendor/bin/pint
```

## Variables importantes

No guardar secretos reales en Git. La contraseña SMTP vista en capturas debe considerarse comprometida y rotarse antes de produccion.

- `APP_TIMEZONE=America/Merida`
- `APP_LOCALE=es`
- `DB_CONNECTION=mysql`
- `QUEUE_CONNECTION=database`
- `MAIL_HOST=smtp.hostinger.com`
- `MAIL_PORT=465`
- `MAIL_ENCRYPTION=ssl`

Diagnostico SMTP:

```bash
php artisan orvix:mail-test correo@ejemplo.com
```

## Documentacion

- [Arquitectura](docs/arquitectura.md)
- [Modelo de datos](docs/modelo-datos.md)
- [Reglas de negocio](docs/reglas-negocio.md)
- [Matriz de permisos](docs/matriz-permisos.md)
- [Sistema visual](docs/design-system.md)
- [Despliegue Hostinger](docs/despliegue-hostinger.md)
- [Respaldo y restauracion](docs/respaldo-restauracion.md)
- [Manual administrador](docs/manual-administrador.md)
- [Manual operador](docs/manual-operador.md)
- [Changelog](CHANGELOG.md)
