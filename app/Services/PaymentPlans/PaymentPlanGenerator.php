<?php

namespace App\Services\PaymentPlans;

use App\Contracts\BusinessCalendar;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * Generador de plan de cobros por cadencia de facturacion.
 *
 * Sobre esta jerarquia conviven dos patrones:
 *
 * - Factory Method: cada cadencia sabe cuantos cortes produce, cuando se
 *   emiten y cuando vencen. El servicio no conoce ninguna cadencia, solo pide
 *   el generador correcto.
 * - Template Method: generate() fija la secuencia comun (crear los cortes y
 *   refrescar su estado) y deja en createInvoices() el paso variable. La
 *   busqueda/creacion idempotente vive en ensureInvoice(), con findInvoice()
 *   como hook para la unica diferencia entre cadencias.
 *
 * El vencimiento usa el BusinessCalendar (Adapter): las subclases no calculan
 * fechas habiles a mano, delegan en el Target inyectado.
 */
abstract class PaymentPlanGenerator
{
    public function __construct(
        protected Rental $rental,
        protected InvoiceNumberService $numbers,
        protected AppSettings $settings,
        protected BusinessCalendar $calendar,
    ) {}

    /**
     * Genera las facturas del plan.
     *
     * @return array<Invoice>
     */
    abstract protected function createInvoices(): array;

    /**
     * Ejecuta la generacion de facturas para este alquiler.
     *
     * Este es el metodo plantilla: la secuencia no varia entre cadencias.
     * Primero cada subclase decide sus cortes (createInvoices) y despues la
     * base refresca el estado de todas las facturas, para que los meses ya
     * transcurridos queden vencidos sin que cada subclase deba recordarlo.
     *
     * Devuelve las facturas creadas (o existentes, si eran idempotentes) para
     * que quien lo consuma pueda informar cuantos cortes genero.
     *
     * @return array<Invoice>
     */
    final public function generate(): array
    {
        $invoices = $this->createInvoices();

        foreach ($invoices as $invoice) {
            $invoice->refreshStatus();
        }

        return $invoices;
    }

    /**
     * Recupera la factura ya emitida para el corte o la crea.
     *
     * Paso primitivo compartido por las tres cadencias: evita duplicar la
     * consulta y el arreglo de atributos en cada subclase. La unica diferencia
     * la aporta findInvoice(), que la cadencia mensual sobrescribe.
     */
    protected function ensureInvoice(Carbon $date, float $amount): Invoice
    {
        return $this->findInvoice($date)
            ?? Invoice::create($this->invoiceAttributes($date, $amount));
    }

    /**
     * Hook de idempotencia.
     *
     * Por defecto un corte se identifica por periodo y fecha de emision: es lo
     * que necesita la cadencia quincenal, que emite dos facturas dentro del
     * mismo mes. La mensual sobrescribe este metodo para identificar por
     * periodo solamente, porque un mes ya facturado no debe duplicarse aunque
     * cambie la fecha exacta del corte.
     */
    protected function findInvoice(Carbon $date): ?Invoice
    {
        return Invoice::query()
            ->where('rental_id', $this->rental->id)
            ->where('user_id', $this->rental->user_id)
            ->where('period', Invoice::periodFor($date))
            ->where('issued_at', $date->toDateString())
            ->first();
    }

    /**
     * Atributos comunes de una factura de canon.
     *
     * @return array<string, mixed>
     */
    protected function invoiceAttributes(Carbon $date, float $amount): array
    {
        return [
            'number' => $this->numbers->next((int) $this->rental->user_id, (int) $date->format('Y')),
            'concept' => 'rent',
            'amount' => $amount,
            'period' => Invoice::periodFor($date),
            'issued_at' => $date->toDateString(),
            'due_at' => $this->dueDateFrom($date, $this->settings->invoiceDueDays()),
            'status' => InvoiceStatus::EMITIDA,
            'rental_id' => $this->rental->id,
            'user_id' => $this->rental->user_id,
        ];
    }

    /**
     * Fecha de vencimiento en dia habil.
     *
     * El vencimiento natural es la emision mas los dias de plazo configurados;
     * si ese dia cae en fin de semana o festivo, se corre al siguiente dia
     * habil. El calculo vive en la base para que las tres cadencias lo hereden
     * y solo el calendario sepa de festivos.
     */
    protected function dueDateFrom(Carbon $issued, int $dueDays): string
    {
        return $this->calendar
            ->nextBusinessDay($issued->copy()->addDays($dueDays))
            ->toDateString();
    }
}
