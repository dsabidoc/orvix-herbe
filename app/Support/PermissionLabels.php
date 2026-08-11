<?php

namespace App\Support;

final class PermissionLabels
{
    /**
     * @return array{label:string, description:string, group:string}
     */
    public static function get(string $permission): array
    {
        return self::map()[$permission] ?? [
            'label' => self::humanize($permission),
            'description' => 'Permiso personalizado del sistema.',
            'group' => 'Personalizados',
        ];
    }

    public static function label(string $permission): string
    {
        return self::get($permission)['label'];
    }

    public static function description(string $permission): string
    {
        return self::get($permission)['description'];
    }

    public static function group(string $permission): string
    {
        return self::get($permission)['group'];
    }

    /**
     * @return array<string, array{label:string, description:string, group:string}>
     */
    private static function map(): array
    {
        return [
            'users.manage' => ['label' => 'Administrar usuarios', 'description' => 'Crear, editar, desactivar y cambiar accesos de usuarios.', 'group' => 'Configuracion'],
            'settings.manage' => ['label' => 'Administrar configuracion', 'description' => 'Entrar a usuarios, roles, permisos y ajustes generales.', 'group' => 'Configuracion'],
            'operators.manage' => ['label' => 'Administrar operadores', 'description' => 'Crear y modificar operadores de cartera.', 'group' => 'Operadores'],
            'clients.view-all' => ['label' => 'Ver todos los clientes', 'description' => 'Consultar clientes de cualquier operador.', 'group' => 'Clientes'],
            'clients.view-assigned' => ['label' => 'Ver clientes asignados', 'description' => 'Consultar solo clientes propios o asignados.', 'group' => 'Clientes'],
            'clients.create' => ['label' => 'Crear clientes', 'description' => 'Dar de alta clientes nuevos.', 'group' => 'Clientes'],
            'clients.manage' => ['label' => 'Administrar clientes', 'description' => 'Crear, editar y completar informacion de clientes.', 'group' => 'Clientes'],
            'vehicles.manage' => ['label' => 'Administrar vehiculos', 'description' => 'Crear y editar informacion de vehiculos.', 'group' => 'Vehiculos'],
            'vehicles.view-assigned' => ['label' => 'Ver vehiculos asignados', 'description' => 'Consultar vehiculos de su cartera.', 'group' => 'Vehiculos'],
            'applications.create' => ['label' => 'Crear solicitudes', 'description' => 'Capturar solicitudes de credito para revision.', 'group' => 'Solicitudes'],
            'applications.authorize' => ['label' => 'Autorizar solicitudes', 'description' => 'Aprobar, ajustar condiciones o rechazar solicitudes.', 'group' => 'Solicitudes'],
            'loans.view-assigned' => ['label' => 'Ver prestamos asignados', 'description' => 'Consultar solo prestamos propios o asignados.', 'group' => 'Prestamos'],
            'loans.formalize' => ['label' => 'Crear prestamos', 'description' => 'Formalizar prestamos y generar calendario de pagos.', 'group' => 'Prestamos'],
            'installments.view-assigned' => ['label' => 'Ver letras asignadas', 'description' => 'Consultar letras de pago de su cartera.', 'group' => 'Cobranza'],
            'payments.report' => ['label' => 'Reportar pagos', 'description' => 'Marcar letras como pagadas para corte semanal.', 'group' => 'Cobranza'],
            'payments.confirm' => ['label' => 'Confirmar pagos', 'description' => 'Confirmar recepcion y aplicar pagos al saldo.', 'group' => 'Cobranza'],
            'payments.report-authorized' => ['label' => 'Reportar pagos autorizados', 'description' => 'Registrar pagos con autorizacion documental.', 'group' => 'Cobranza'],
            'weekly-cuts.prepare' => ['label' => 'Preparar cortes', 'description' => 'Revisar y preparar cortes semanales.', 'group' => 'Cortes'],
            'weekly-cuts.submit' => ['label' => 'Enviar cortes', 'description' => 'Generar y enviar corte semanal del operador.', 'group' => 'Cortes'],
            'weekly-cuts.confirm' => ['label' => 'Recibir cortes', 'description' => 'Confirmar efectivo recibido y liquidar diferencias.', 'group' => 'Cortes'],
            'operator-ledger.view-own' => ['label' => 'Ver saldo propio', 'description' => 'Consultar saldo y diferencias propias del operador.', 'group' => 'Cortes'],
            'adjustments.approve' => ['label' => 'Aprobar ajustes', 'description' => 'Autorizar ajustes de saldos o movimientos.', 'group' => 'Administracion'],
            'settlements.authorize' => ['label' => 'Autorizar liquidaciones', 'description' => 'Liquidar creditos o saldos pendientes.', 'group' => 'Liquidaciones'],
            'settlements.prepare-documents' => ['label' => 'Preparar documentos de liquidacion', 'description' => 'Gestionar documentos necesarios para liquidaciones.', 'group' => 'Liquidaciones'],
            'documents.manage' => ['label' => 'Administrar expedientes', 'description' => 'Subir y mantener documentos de clientes y prestamos.', 'group' => 'Documentos'],
            'promissory-notes.manage' => ['label' => 'Administrar pagares', 'description' => 'Controlar pagares, custodia y entregas.', 'group' => 'Documentos'],
            'investors.manage' => ['label' => 'Administrar inversionistas', 'description' => 'Crear y modificar inversionistas.', 'group' => 'Inversionistas'],
            'investments.view-own' => ['label' => 'Ver inversiones propias', 'description' => 'Consultar inversiones asignadas al inversionista.', 'group' => 'Inversionistas'],
            'investor-reports.view-own' => ['label' => 'Ver reportes propios de inversion', 'description' => 'Consultar reportes de rendimiento propios.', 'group' => 'Inversionistas'],
            'investor-withdrawals.request' => ['label' => 'Solicitar retiro propio', 'description' => 'Pedir retiros de capital disponible como inversionista.', 'group' => 'Inversionistas'],
            'investor-withdrawals.manage' => ['label' => 'Administrar retiros de inversionistas', 'description' => 'Aprobar, rechazar y registrar retiros de capital.', 'group' => 'Inversionistas'],
            'portfolio.view' => ['label' => 'Ver cartera y saldos', 'description' => 'Consultar cartera por operador, cliente, vehiculo y fecha de corte.', 'group' => 'Reportes'],
            'portfolio.export' => ['label' => 'Exportar cartera y saldos', 'description' => 'Descargar el reporte de cartera y saldos con los filtros aplicados.', 'group' => 'Reportes'],
            'reports.view-all' => ['label' => 'Ver todos los reportes', 'description' => 'Consultar reportes globales de cartera, cobranza y cortes.', 'group' => 'Reportes'],
            'audit.view' => ['label' => 'Ver auditoria', 'description' => 'Consultar historial de cambios y acciones del sistema.', 'group' => 'Auditoria'],
            'exports.run' => ['label' => 'Exportar informacion', 'description' => 'Generar impresiones, descargas o exportaciones.', 'group' => 'Reportes'],
        ];
    }

    private static function humanize(string $permission): string
    {
        return ucfirst(str_replace(['.', '-', '_'], ' ', $permission));
    }
}
