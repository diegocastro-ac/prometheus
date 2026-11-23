<?php

namespace App\Support;

use App\Enums\ActItemState;
use App\Enums\AdjustmentState;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Enums\PaymentStatus;
use App\Enums\RentalType;
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
    /** Columnas donde un valor tipo estado merece badge. Incluye los
     *  alias en espanol porque el SQL del modelo nombra las columnas
     *  como quiera ("frecuencia", "estado_pago"); sin ellos esas
     *  columnas nunca llegaban ni al badge ni al colorista de la IA. */
    private const STATUS_COLUMNS = [
        'status', 'state', 'payment_status', 'invoice_status', 'type',
        'method', 'cadence', 'billing_cadence', 'rental_type', 'act_type',
        'kind', 'basis', 'pactado',
        'estado', 'estado_pago', 'estado_factura', 'estado_arriendo',
        'estado_contrato', 'frecuencia', 'frecuencia_cobro',
        'frecuencia_facturacion', 'cadencia', 'periodicidad',
        'tipo', 'tipo_arriendo', 'tipo_documento', 'tipo_acta',
        'metodo', 'metodo_pago', 'forma_pago', 'payment_method',
        'condicion', 'condicion_pago', 'plazo', 'categoria', 'category',
        'result', 'resultado', 'outcome', 'etapa', 'fase',
    ];

    /** Columnas booleanas: si/no se pintan como badge de exito/peligro. */
    private const YES_NO_COLUMNS = [
        'is_active', 'pactado', 'active', 'signed', 'overdue',
        'activo', 'activa', 'vigente', 'enabled', 'firmada',
    ];

    /**
     * ¿La columna puede mostrar un badge de estado? Lo usa el colorista
     * de la IA para no gastar tokens en columnas que nunca se pintan
     * (montos, nombres, fechas). Las booleanas quedan fuera a proposito:
     * su color ya lo decide la logica si/no, la IA nunca las pisa.
     */
    public static function isStatusColumn(?string $column): bool
    {
        return $column !== null
            && in_array(Str::lower($column), self::STATUS_COLUMNS, true);
    }

    /** Tipos de alerta del analisis financiero (columna `type`). */
    private const ALERT_TYPES = [
        'adjustment_due' => 'warning',
        'contract_expiring' => 'info',
    ];

    /**
     * @param  string|null  $aiColor  color propuesto por la IA para esta
     *                               columna ({tabla: {columna: color}} del
     *                               reply). Solo se usa cuando el valor
     *                               no matchea ningun enum ni alerta
     *                               conocida; el color de dominio siempre
     *                               tiene prioridad.
     * @return array{label: string, classes: string}|null
     */
    public static function for(?string $column, mixed $value, ?string $aiColor = null): ?array
    {
        $columnKey = Str::lower($column);
        $isCategoricalColumn = in_array($columnKey, self::STATUS_COLUMNS, true)
            || in_array($columnKey, self::YES_NO_COLUMNS, true);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // "—" y vacios visuales no son estados: se dejan como texto plano.
        if (in_array(trim($value), ['—', '-', '–'], true)) {
            return null;
        }

        $key = str_replace(' ', '_', Str::lower(trim($value)));

        // Los enums de dominio se reconocen por el VALOR, no por el
        // nombre de la columna: el modelo puede aliasar "frecuencia" o
        // "estado" y un badge limitado a nombres en ingles se perdia el
        // estado. Un falso positivo exige que TODA la celda sea
        // exactamente un valor/etiqueta de enum ("MENSUAL", "Pagada"),
        // algo que un texto libre casi nunca produce.
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

        // Estado del reajuste: llega como valor ('applied') o como etiqueta
        // traducida ('Aplicado'). Cada estado del enum ya define su color.
        foreach (AdjustmentState::cases() as $state) {
            if ($key === $state->value || $key === Str::lower(Str::ascii($state->label()))) {
                return ['label' => $state->label(), 'classes' => self::classes($state->color())];
            }
        }

        // Catalogos categoricos sin carga de estado: badge informativo
        // neutro para que la tabla se vea pareja. Se acepta el valor crudo
        // y la etiqueta traducida.
        foreach ([BillingCadence::class, RentalType::class, NotificationMethod::class, IpcBasis::class] as $enum) {
            foreach ($enum::cases() as $case) {
                if ($key === Str::lower($case->value) || $key === Str::lower(Str::ascii($case->label()))) {
                    return ['label' => $case->label(), 'classes' => self::classes('info')];
                }
            }
        }

        // De aqui en adelante el badge depende de la columna: las alertas,
        // el si/no y el fallback con color de la IA solo aplican a
        // columnas categoricas. El aiColor nunca llega a columnas fuera
        // de la lista (el muestreador no las envia al colorista).
        if (! $isCategoricalColumn) {
            return null;
        }

        // 'overdue' ya cayo en PaymentStatus; aqui quedan los tipos de alerta
        // que no son estados de una entidad.
        if (isset(self::ALERT_TYPES[$key])) {
            return [
                'label' => __("assistant.alert_types.{$key}"),
                'classes' => self::classes(self::ALERT_TYPES[$key]),
            ];
        }

        // Columnas si/no (is_active, pactado): el valor llega traducido
        // ("Sí"/"No") o crudo; se decide por el contenido.
        if (in_array($columnKey, self::YES_NO_COLUMNS, true)) {
            $affirmative = in_array($key, ['si', 'sí', 'yes', 'true', '1', 'activo', 'active'], true);

            return [
                'label' => $value,
                'classes' => self::classes($affirmative ? 'success' : 'danger'),
            ];
        }

        // Estado reconocido por nombre pero no por enum (p.ej. un metodo de
        // pago o cadencia en texto libre): si la IA propuso un color
        // coherente para la columna se usa; si no, gris por defecto para
        // que la tabla se vea pareja en vez de texto suelto.
        return ['label' => $value, 'classes' => self::classes($aiColor ?? 'default')];
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
