<?php

namespace App\Services;

use App\Enums\BillingCadence;
use App\Models\Rental;
use App\Services\PaymentPlans\PaymentPlanGeneratorResolver;
use Filament\Notifications\Notification;

/**
 * Genera el plan de cobros de un alquiler segun su cadencia.
 *
 * El servicio ya no conoce las fechas de ninguna cadencia. Delega en el
 * generador que corresponda mediante Factory Method, y se limita a ejecutar
 * y notificar. Tambien mantiene la idempotencia a nivel del flujo: no intenta
 * borrar facturas existentes; el generador se encarga de no duplicarlas.
 */
class PaymentPlanService
{
    public function __construct(
        private readonly PaymentPlanGeneratorResolver $resolver,
    ) {}

    public function generateFor(Rental $rental, ?BillingCadence $cadence = null): void
    {
        $cadence ??= $rental->billing_cadence ?? BillingCadence::MENSUAL;
        $generator = $this->resolver->resolve($rental, $cadence);
        $generator->generate();

        Notification::make()
            ->success()
            ->title(__('invoice.created'))
            ->send();
    }
}
