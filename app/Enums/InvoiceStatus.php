<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case EMITIDA = 'emitida';
    case PAGADA = 'pagada';
    case VENCIDA = 'vencida';
    case ANULADA = 'anulada';

    public function label(): string
    {
        return __('invoice.statuses.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::EMITIDA => 'gray',
            self::PAGADA => 'success',
            self::VENCIDA => 'danger',
            self::ANULADA => 'warning',
        };
    }

    /**
     * Una factura anulada no vuelve a cambiar sola: si el arrendador la anulo,
     * el sistema respeta esa decision aunque queden pagos registrados.
     */
    public function isFinal(): bool
    {
        return $this === self::ANULADA;
    }
}
