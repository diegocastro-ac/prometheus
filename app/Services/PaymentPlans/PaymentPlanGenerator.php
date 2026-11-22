<?php

namespace App\Services\PaymentPlans;

use App\Contracts\BusinessCalendar;
use App\Models\Invoice;
use App\Models\Rental;
use Illuminate\Support\Carbon;

/**
 * Generador de plan de cobros por cadencia de facturacion.
 *
 * El Factory Method se justifica aqui: cada cadencia sabe cuantos cortes
 * produce, cuando se emiten y cuando vencen. El servicio no conoce ninguna
 * cadencia, solo pide el generador correcto.
 *
 * El vencimiento usa el BusinessCalendar (Adapter): las subclases no calculan
 * fechas habiles a mano, delegan en el Target inyectado.
 */
abstract class PaymentPlanGenerator
{
    public function __construct(
        protected Rental $rental,
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
     * Devuelve las facturas creadas (o existentes, si eran idempotentes) para
     * que quien lo consuma pueda informar cuantos cortes genero.
     *
     * @return array<Invoice>
     */
    public function generate(): array
    {
        return $this->createInvoices();
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
