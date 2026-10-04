<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Settings\AppSettings;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Genera el plan de cobros mensual de un alquiler.
 *
 * Emite facturas, no pagos: un pago es un abono contra una factura, asi que
 * crear filas en la tabla de pagos aqui no tendria sentido. El servicio todavia
 * decide las fechas por su cuenta; convertirlo en generadores por cadencia es
 * el paso siguiente.
 */
class PaymentPlanService
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly AppSettings $settings,
    ) {}

    public function generateFor(Rental $rental): void
    {
        $start = Carbon::parse($rental->start_date);
        $dueDays = $this->settings->invoiceDueDays();

        for ($month = 1; $month <= $rental->total_months; $month++) {
            $period = $start->copy()->addMonths($month);

            $invoice = Invoice::create([
                'number' => $this->numbers->next((int) $rental->user_id, (int) $period->format('Y')),
                // La clave, no el texto traducido: el concepto se guarda como
                // 'rent' para que un filtro por concepto funcione igual en todo
                // el sistema y la etiqueta la ponga la vista.
                'concept' => 'rent',
                'amount' => $rental->monthly_amount,
                'period' => Invoice::periodFor($period),
                'issued_at' => $period->toDateString(),
                'due_at' => $period->copy()->addDays($dueDays)->toDateString(),
                'status' => InvoiceStatus::EMITIDA,
                'rental_id' => $rental->id,
                'user_id' => $rental->user_id,
            ]);

            // El plan recorre el contrato entero, asi que los meses ya
            // transcurridos nacen con la fecha vencida. Sin recalcular, el
            // estado guardado diria emitida mientras isOverdue() responderia
            // que si, y el panel mostraria esas facturas como al dia.
            $invoice->refreshStatus();
        }

        Notification::make()
            ->success()
            ->title(__('invoice.created'))
            ->send();
    }
}
