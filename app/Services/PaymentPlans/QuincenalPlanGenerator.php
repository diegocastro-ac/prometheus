<?php

namespace App\Services\PaymentPlans;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
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
        $half = round($this->rental->monthly_amount / 2, 2);

        for ($month = 1; $month <= $this->rental->total_months; $month++) {
            // Sin desbordamiento, por la misma razon que en la cadencia mensual:
            // un inicio a fin de mes no puede empujar el corte al mes siguiente.
            $base = $start->copy()->addMonthsNoOverflow($month);

            // Primer corte: dia 1 de la quincena
            $first = $base->copy()->startOfMonth();
            $this->createOrEnsure($invoices, $first, $half, $dueDays);

            // Segundo corte: dia 15
            $second = $base->copy()->startOfMonth()->addDays(14);
            $this->createOrEnsure($invoices, $second, $half, $dueDays);
        }

        return $invoices;
    }

    /**
     * @param  array<Invoice>  $invoices
     */
    private function createOrEnsure(array &$invoices, Carbon $date, float $amount, int $dueDays): void
    {
        $periodKey = Invoice::periodFor($date);
        $invoice = Invoice::query()
            ->where('rental_id', $this->rental->id)
            ->where('user_id', $this->rental->user_id)
            ->where('period', $periodKey)
            ->where('issued_at', $date->toDateString())
            ->first();

        if ($invoice === null) {
            $invoice = Invoice::create([
                'number' => $this->numbers->next((int) $this->rental->user_id, (int) $date->format('Y')),
                'concept' => 'rent',
                'amount' => $amount,
                'period' => $periodKey,
                'issued_at' => $date->toDateString(),
                'due_at' => $date->copy()->addDays($dueDays)->toDateString(),
                'status' => InvoiceStatus::EMITIDA,
                'rental_id' => $this->rental->id,
                'user_id' => $this->rental->user_id,
            ]);
        }

        $invoice->refreshStatus();
        $invoices[] = $invoice;
    }
}
