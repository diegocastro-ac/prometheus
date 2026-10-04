<?php

namespace App\Services\PaymentPlans;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * Cadencia mensual: una factura por mes de contrato, emitida al inicio del
 * periodo mensual que corresponde.
 *
 * El generador crea facturas, no pagos. El estado de cada factura se recalcula
 * despues de crearla, porque meses ya transcurridos deben marcarse como
 * vencidos si corresponde.
 */
class MonthlyPlanGenerator extends PaymentPlanGenerator
{
    public function __construct(
        Rental $rental,
        private readonly InvoiceNumberService $numbers,
        private readonly AppSettings $settings,
    ) {
        parent::__construct($rental);
    }

    /**
     * @return array<Invoice>
     */
    protected function createInvoices(): array
    {
        $start = Carbon::parse($this->rental->start_date);
        $dueDays = $this->settings->invoiceDueDays();
        $invoices = [];

        for ($month = 1; $month <= $this->rental->total_months; $month++) {
            // Sin desbordamiento: el 31 de enero mas un mes es el 28 de febrero,
            // no el 3 de marzo. Con addMonths() los periodos se saltarian y dos
            // meses distintos caerian en el mismo YYYY-MM.
            $period = $start->copy()->addMonthsNoOverflow($month);
            $periodKey = Invoice::periodFor($period);

            // Idempotente: no duplicar periodos que ya existen para este alquiler.
            // Esto evita que regenerar el plan cree facturas de mas.
            $invoice = Invoice::query()
                ->where('rental_id', $this->rental->id)
                ->where('user_id', $this->rental->user_id)
                ->where('period', $periodKey)
                ->first();

            if ($invoice === null) {
                $invoice = Invoice::create([
                    'number' => $this->numbers->next((int) $this->rental->user_id, (int) $period->format('Y')),
                    'concept' => 'rent',
                    'amount' => $this->rental->monthly_amount,
                    'period' => $periodKey,
                    'issued_at' => $period->toDateString(),
                    'due_at' => $period->copy()->addDays($dueDays)->toDateString(),
                    'status' => InvoiceStatus::EMITIDA,
                    'rental_id' => $this->rental->id,
                    'user_id' => $this->rental->user_id,
                ]);
            }

            $invoice->refreshStatus();
            $invoices[] = $invoice;
        }

        return $invoices;
    }
}
