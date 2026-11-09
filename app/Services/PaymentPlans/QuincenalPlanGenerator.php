<?php

namespace App\Services\PaymentPlans;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Cadencia quincenal: dos facturas por mes.
 *
 * La logica original distribuia canon y servicios entre las dos quincenas.
 * Para mantener la compatibilidad con lo que el panel espera, este generador
 * crea dos facturas por periodo de contrato, repartiendo el monto del alquiler
 * de forma equitativa. Cada subclase decide los cortes, sin tocar el servicio.
 */
class QuincenalPlanGenerator extends PaymentPlanGenerator
{
    /**
     * @return array<Invoice>
     */
    protected function createInvoices(): array
    {
        $start = Carbon::parse($this->rental->start_date);
        $invoices = [];
        $half = round($this->rental->monthly_amount / 2, 2);

        for ($month = 1; $month <= $this->rental->total_months; $month++) {
            // Sin desbordamiento, por la misma razon que en la cadencia mensual:
            // un inicio a fin de mes no puede empujar el corte al mes siguiente.
            $base = $start->copy()->addMonthsNoOverflow($month);

            // Primer corte: dia 1 de la quincena
            $invoices[] = $this->ensureInvoice($base->copy()->startOfMonth(), $half);

            // Segundo corte: dia 15
            $invoices[] = $this->ensureInvoice($base->copy()->startOfMonth()->addDays(14), $half);
        }

        return $invoices;
    }
}
