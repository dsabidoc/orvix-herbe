# Changelog

## 2026-07-30

- Se creo proyecto Laravel 13.8 para Orvix Prestamos.
- Se instalaron Livewire 4.3 y Spatie Permission 7.4.
- Se agrego modelo de datos inicial para cartera, cobranza, cortes, documentos, pagares, liquidaciones, inversionistas y auditoria.
- Se agregaron roles/permisos iniciales.
- Se implemento el motor de simulacion de prestamos con prueba del ejemplo confirmado.
- Se reemplazo la pantalla inicial por un panel operativo responsivo.
- Se agrego documentacion base de arquitectura, reglas, permisos, despliegue, respaldo y manuales.
- Se integraron minuta, transcripcion y archivo Finstack `.fig` al criterio de producto.
- Se agrego autenticacion local, datos demo realistas, cartera por rol, detalle de prestamo, registro/confirmacion de cobros y cortes semanales.
- Se agregaron pruebas de login, aislamiento de operador y aplicacion de pagos.
- Se agrego modulo global de Cobranza mensual con aislamiento por operador.
- Se enlazaron pagos marcados en Cobranza a letras especificas para alimentar cortes semanales.
- Se agrego corte imprimible con encabezado de operador/semana, pagos marcados y atrasados sin marcar.
- Se agrego arrastre automatico de letras no pagadas al corte de la semana siguiente como atrasadas.
