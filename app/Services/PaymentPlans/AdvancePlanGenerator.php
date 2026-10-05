<?php

namespace App\Services\PaymentPlans;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
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
        $total = $this->rental->monthly_amount * $this->rental->total_months;

        $date = $start->copy();
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
                'amount' => $total,
                'period' => $periodKey,
                'issued_at' => $date->toDateString(),
                'due_at' => $date->copy()->addDays($dueDays)->toDateString(),
                'status' => InvoiceStatus::EMITIDA,
                'rental_id' => $this->rental->id,
                'user_id' => $this->rental->user_id,
            ]);
        }

        $invoice->refreshStatus();

        return [$invoice];
    }
}
