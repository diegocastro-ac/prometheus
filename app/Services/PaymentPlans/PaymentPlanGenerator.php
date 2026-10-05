<?php

namespace App\Services\PaymentPlans;

use App\Models\Invoice;
use App\Models\Rental;

/**
 * Generador de plan de cobros por cadencia de facturacion.
 *
 * El Factory Method se justifica aqui: cada cadencia sabe cuantos cortes
 * produce, cuando se emiten y cuando vencen. El servicio no conoce ninguna
 * cadencia, solo pide el generador correcto.
 */
abstract class PaymentPlanGenerator
{
    public function __construct(protected Rental $rental) {}

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
}
