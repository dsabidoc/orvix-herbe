# Respaldo y restauracion

## Politica inicial

- Base de datos: respaldo diario.
- Documentos privados: respaldo periodico cifrado.
- Retencion rotativa y copia fuera del mismo hosting cuando sea posible.
- Registro de fecha, resultado y responsable.

## Restauracion

Un respaldo solo se considera valido despues de restaurarlo en un entorno separado y comparar:

- Conteo de tablas criticas.
- Totales de cartera.
- Totales de movimientos.
- Muestras de documentos privados.
- Usuarios y permisos.

## Pendiente

Automatizar comando de respaldo despues de confirmar capacidades reales del plan Hostinger.
