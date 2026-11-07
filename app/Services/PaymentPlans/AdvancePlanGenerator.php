<?php

namespace App\Services\PaymentPlans;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Cadencia de anticipo: una unica factura por el monto total del contrato,
 * emitida al inicio del periodo de alquiler.
 *
 * Mantiene la semantica de "anticipo": se factura la totalidad por adelantado
 * en lugar de generar varios cortes.
 */
class AdvancePlanGenerator extends PaymentPlanGenerator
{
    /**
     * @return array<Invoice>
     */
    protected function createInvoices(): array
    {
        $start = Carbon::parse($this->rental->start_date);
        $total = $this->rental->monthly_amount * $this->rental->total_months;

        return [$this->ensureInvoice($start, $total)];
    }
}
