<?php

namespace App\Support;

use App\Enums\ActItemState;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use Illuminate\Support\Str;

/**
 * Convierte celdas de estado de las tablas del asistente en badges de color.
 *
 * Los datos llegan como valores crudos de enum (`vencida`, `paid`) o como
 * etiquetas ya traducidas (`Reparable`); se normaliza el texto antes de
 * comparar para que ambos casos produzcan el mismo badge. Solo aplica a las
 * columnas que pueden contener estados: el resto de celdas queda igual.
 */
final class AssistantStatusBadge
{
    /** Columnas donde un valor tipo estado merece badge. */
    private const STATUS_COLUMNS = ['status', 'state', 'payment_status', 'invoice_status', 'type'];

    /** Tipos de alerta del analisis financiero (columna `type`). */
    private const ALERT_TYPES = [
        'adjustment_due' => 'warning',
        'contract_expiring' => 'info',
    ];

    /**
     * @return array{label: string, classes: string}|null
     */
    public static function for(?string $column, mixed $value): ?array
    {
        if ($column === null || ! in_array(Str::lower($column), self::STATUS_COLUMNS, true)) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $key = str_replace(' ', '_', Str::lower(trim($value)));

        if ($invoice = InvoiceStatus::tryFrom($key)) {
            return ['label' => $invoice->label(), 'classes' => self::classes($invoice->color())];
        }

        if ($payment = PaymentStatus::tryFrom($key)) {
            return [
                'label' => __('payments.status.'.$payment->value),
                'classes' => self::classes(self::paymentColor($payment)),
            ];
        }

        if ($item = ActItemState::tryFrom($key)) {
            return ['label' => $item->label(), 'classes' => self::classes(self::itemColor($item))];
        }

        // 'overdue' ya cayo en PaymentStatus; aqui quedan los tipos de alerta
        // que no son estados de una entidad.
        if (isset(self::ALERT_TYPES[$key])) {
            return [
                'label' => __("assistant.alert_types.{$key}"),
                'classes' => self::classes(self::ALERT_TYPES[$key]),
            ];
        }

        return null;
    }

    private static function paymentColor(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::PAID => 'success',
            PaymentStatus::PARTIAL => 'info',
            PaymentStatus::PENDING => 'warning',
            PaymentStatus::OVERDUE => 'danger',
        };
    }

    private static function itemColor(ActItemState $state): string
    {
        return match ($state) {
            ActItemState::NUEVO => 'info',
            ActItemState::BUENO => 'success',
            ActItemState::REPARABLE => 'warning',
            ActItemState::POR_REEMPLAZAR, ActItemState::DESTRUIDO => 'danger',
        };
    }

    private static function classes(string $color): string
    {
        return 'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 '
            .match ($color) {
                'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-400/30',
                'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300 dark:ring-danger-400/30',
                'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-400/30',
                'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300 dark:ring-info-400/30',
                default => 'bg-gray-100 text-gray-600 ring-gray-950/10 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10',
            };
    }
}
