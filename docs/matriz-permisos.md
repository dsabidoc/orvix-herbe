# Matriz de permisos

| Recurso / accion | Administrador | Operador | Documental | Inversionista |
| --- | --- | --- | --- | --- |
| Usuarios | Administrar | No | No | No |
| Operadores | Administrar | Ver propio | No | No |
| Clientes | Todos | Asignados | Todos | No |
| Vehiculos | Administrar | Asignados | Consultar | No |
| Solicitudes | Autorizar | Crear asignadas | Documentar | No |
| Prestamos | Formalizar | Ver asignados | Consultar expediente | Relacionados limitados |
| Cobros | Confirmar | Reportar | Reportar autorizado | No |
| Cortes | Confirmar | Preparar/enviar propio | Consultar si aplica | No |
| Cuenta corriente operador | Todos | Propia | No | No |
| Documentos | Autorizar/ver | Limitado | Administrar | No |
| Pagares | Autorizar/ver | Limitado | Administrar | No |
| Liquidaciones | Autorizar | Solicitar | Preparar docs | No |
| Inversionistas | Administrar | No | No | Ver propio |
| Auditoria | Ver | No | No | No |

Los permisos seed iniciales viven en `database/seeders/RolePermissionSeeder.php`.
