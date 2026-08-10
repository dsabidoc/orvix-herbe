# Despliegue Hostinger

## Verificacion previa

- PHP 8.4 activo y extensiones requeridas por Laravel.
- MySQL/MariaDB disponible.
- Composer disponible o build preparado desde local/CI.
- Node/npm para build de assets, o artefacto `public/build` generado antes de subir.
- SSH, cron y SSL.
- Limites de memoria, upload y tiempo de ejecucion revisados.

## Flujo

1. Rotar cualquier secreto visto en capturas.
2. Hacer respaldo de base de datos y documentos.
3. Activar mantenimiento si hay usuarios reales.
4. Instalar dependencias de produccion.
5. Ejecutar build de assets.
6. Ejecutar migraciones con `--force`.
7. Cachear configuracion, rutas y vistas.
8. Configurar cron del scheduler por minuto.
9. Procesar cola con worker persistente o cron anti-solapamiento.
10. Smoke test de login, panel, correo, storage privado y consulta de cartera.

## Seguridad de document root

El document root debe apuntar a `/public`. Nunca exponer `.env`, `vendor`, `storage`, dumps, backups ni la raiz del proyecto.
