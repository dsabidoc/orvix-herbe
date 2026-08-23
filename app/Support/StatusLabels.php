<?php

namespace App\Support;

final class StatusLabels
{
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

    public static function cut(?string $status): string
    {
        return [
            'draft' => 'Pendiente',
            'forming' => 'Pendiente',
            'submitted' => 'Pendiente',
            'pending_reconciliation' => 'Pendiente',
            'partially_received' => 'En validacion',
            'reviewing' => 'En validacion',
            'confirmed' => 'En validacion',
            'balanced' => 'En validacion',
            'with_difference' => 'En validacion',
            'closed' => 'Cerrado',
            'reopened' => 'Pendiente',
            'returned' => 'Devuelto',
            'voided' => 'Anulado',
        ][$status] ?? self::fallback($status);
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
