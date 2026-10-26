<?php

namespace App\Support;

final class StatusLabels
{
    private const CUT_GROUPS = [
        'pending' => ['label' => 'Pendiente', 'statuses' => ['draft', 'forming', 'submitted', 'pending_reconciliation', 'reopened']],
        'reviewing' => ['label' => 'En validacion', 'statuses' => ['partially_received', 'reviewing', 'confirmed', 'balanced', 'with_difference']],
        'closed' => ['label' => 'Cerrado', 'statuses' => ['closed']],
        'returned' => ['label' => 'Devuelto', 'statuses' => ['returned']],
        'voided' => ['label' => 'Anulado', 'statuses' => ['voided']],
    ];

    public static function movement(?string $status): string
    {
        return [
            'reported' => 'Por confirmar',
            'applied' => 'Aplicado',
            'voided' => 'Anulado',
            'rejected' => 'En aclaracion',
            'reversed' => 'Revertido',
        ][$status] ?? self::fallback($status);
    }

    public static function collectionMovementType(?string $type): string
    {
        return [
            'ordinary' => 'Pago de letra',
            'partial' => 'Abono parcial',
            'advance' => 'Adelanto a capital',
            'capital_advance' => 'Adelanto a capital',
            'settlement' => 'Liquidación',
        ][$type] ?? self::fallback($type);
    }

    public static function cut(?string $status): string
    {
        foreach (self::CUT_GROUPS as $group) {
            if (in_array($status, $group['statuses'], true)) {
                return $group['label'];
            }
        }

        return self::fallback($status);
    }

    public static function cutFilterOptions(): array
    {
        return array_map(fn (array $group) => $group['label'], self::CUT_GROUPS);
    }

    public static function cutStatusesForFilter(string $filter): array
    {
        return self::CUT_GROUPS[$filter]['statuses'] ?? [];
    }

    public static function installment(?string $status): string
    {
        return [
            'upcoming' => 'Pendiente',
            'due_today' => 'Vence hoy',
            'overdue' => 'Vencida',
            'reported' => 'Por confirmar',
            'pending_delivery' => 'Pendiente de entrega',
            'partial' => 'Parcial',
            'confirmed' => 'Pagada',
            'advanced' => 'Adelantada',
            'cancelled_by_settlement' => 'Cancelada por liquidacion',
        ][$status] ?? self::fallback($status);
    }

    public static function ledger(?string $type): string
    {
        return [
            'confirmed_delivery' => 'Entrega confirmada',
            'shortfall' => 'Faltante',
            'overage' => 'Sobrante',
            'regularization' => 'Regularizacion',
            'funds_delivered' => 'Fondos entregados',
            'adjustment_in' => 'Ajuste de entrada',
            'adjustment_out' => 'Ajuste de salida',
        ][$type] ?? self::fallback($type);
    }

    public static function disbursement(?string $status): string
    {
        return [
            'registered' => 'Registrado',
            'cancelled' => 'Cancelado',
            'reversed' => 'Revertido',
        ][$status] ?? self::fallback($status);
    }

    public static function loan(?string $status): string
    {
        return [
            'formalizing' => 'En formalizacion',
            'active' => 'Activo',
            'frozen' => 'Congelado',
            'settled' => 'Liquidado',
            'closed' => 'Finalizado',
            'defaulted' => 'Incobrable',
        ][$status] ?? self::fallback($status);
    }

    public static function client(?string $status): string
    {
        return [
            'active' => 'Activo',
            'prospect' => 'Prospecto',
            'good_payer' => 'Buen pagador',
            'watchlist' => 'En observacion',
            'inactive' => 'Inactivo',
            'blocked' => 'Bloqueado',
            'merged' => 'Fusionado',
        ][$status] ?? self::fallback($status);
    }

    public static function application(?string $status): string
    {
        return [
            'draft' => 'Borrador',
            'submitted' => 'En revision',
            'approved' => 'Autorizada',
            'rejected' => 'Rechazada',
            'started' => 'Comenzada',
        ][$status] ?? self::fallback($status);
    }

    public static function document(?string $status): string
    {
        return [
            'delivered' => 'Entregado',
            'pending' => 'Pendiente',
            'rejected' => 'Rechazado',
            'expired' => 'Vencido',
        ][$status] ?? self::fallback($status);
    }

    public static function settlementReason(?string $reason): string
    {
        return [
            'pronto_pago_cliente' => 'Pronto pago del cliente',
            'dejo_de_pagar' => 'Dejo de pagar; cobrador liquida',
            'calendario_pagado' => 'Calendario pagado completo',
        ][$reason] ?? self::fallback($reason);
    }

    private static function fallback(?string $value): string
    {
        return ucfirst(str_replace('_', ' ', (string) $value));
    }
}
