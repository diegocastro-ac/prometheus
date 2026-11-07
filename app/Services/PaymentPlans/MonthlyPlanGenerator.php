<?php

namespace App\Services\PaymentPlans;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Cadencia mensual: una factura por mes de contrato, emitida al inicio del
 * periodo mensual que corresponde.
 *
 * El generador crea facturas, no pagos. El estado de cada factura se recalcula
 * en generate(), despues de crear todos los cortes: meses ya transcurridos
 * deben marcarse como vencidos si corresponde.
 */
class MonthlyPlanGenerator extends PaymentPlanGenerator
{
    /**
     * @return array<Invoice>
     */
    protected function createInvoices(): array
    {
        $start = Carbon::parse($this->rental->start_date);
        $invoices = [];

        for ($month = 1; $month <= $this->rental->total_months; $month++) {
            // Sin desbordamiento: el 31 de enero mas un mes es el 28 de febrero,
            // no el 3 de marzo. Con addMonths() los periodos se saltarian y dos
            // meses distintos caerian en el mismo YYYY-MM.
            $period = $start->copy()->addMonthsNoOverflow($month);

            $invoices[] = $this->ensureInvoice($period, $this->rental->monthly_amount);
        }

        return $invoices;
    }

    /**
     * La cadencia mensual identifica el corte por periodo, no por fecha exacta.
     *
     * Si un mes ya esta facturado para el alquiler, regenerar el plan no debe
     * crear otra factura aunque la fecha de inicio haya cambiado. Es la unica
     * cadencia que necesita este criterio; las otras dos comparten periodo y
     * por eso el hook por defecto usa tambien la fecha de emision.
     */
    protected function findInvoice(Carbon $date): ?Invoice
    {
        return Invoice::query()
            ->where('rental_id', $this->rental->id)
            ->where('user_id', $this->rental->user_id)
            ->where('period', Invoice::periodFor($date))
            ->first();
    }
}
