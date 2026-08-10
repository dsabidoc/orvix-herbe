# Manual administrador

## Alcance inicial

El administrador controla usuarios, operadores, cartera completa, autorizaciones, cortes, liquidaciones, inversionistas, reportes y auditoria.

## Operacion esperada

- No confirmar movimientos sin revisar evidencia y contexto.
- No editar datos financieros confirmados; usar anulacion y movimiento compensatorio.
- Confirmar cortes solo despues de registrar recibido real.
- Revisar diferencias acumuladas por operador.
- Rotar secretos comprometidos antes de produccion.

## Diagnostico correo

Usar `php artisan orvix:mail-test correo@ejemplo.com` con credenciales SMTP ya configuradas en `.env`.
