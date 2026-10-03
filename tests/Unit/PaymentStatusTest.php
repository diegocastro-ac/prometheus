<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El estado de un pago no se guarda en la base de datos: se deriva de los
 * cuatro indicadores y de la fecha de vencimiento. Estos tests fijan esa
 * jerarquia para que no vuelva a romperse en silencio.
 */
class PaymentStatusTest extends TestCase
{
    private function payment(Carbon $date, bool $rent, bool $water, bool $energy, bool $gas): Payment
    {
        $payment = new Payment();
        $payment->date = $date;
        $payment->is_rent_paid = $rent;
        $payment->is_water_paid = $water;
        $payment->is_energy_paid = $energy;
        $payment->is_gas_paid = $gas;

        return $payment;
    }

    #[Test]
    public function los_cuatro_servicios_pagados_reportan_pagado(): void
    {
        $payment = $this->payment(today()->subDay(), true, true, true, true);

        $this->assertSame(PaymentStatus::PAID, $payment->status());
    }

    #[Test]
    public function un_pago_completo_sigue_pagado_aunque_la_fecha_haya_pasado(): void
    {
        $payment = $this->payment(today()->subMonths(3), true, true, true, true);

        $this->assertSame(PaymentStatus::PAID, $payment->status());
    }

    #[Test]
    public function un_pago_parcial_vencido_reporta_vencido_y_no_parcial(): void
    {
        // Este es el caso que estaba roto: la mora se evaluaba despues del
        // pago parcial, asi que un pago a medias vencido nunca se reportaba.
        $payment = $this->payment(today()->subDays(10), true, false, false, false);

        $this->assertSame(PaymentStatus::OVERDUE, $payment->status());
    }

    #[Test]
    public function un_pago_parcial_a_tiempo_reporta_parcial(): void
    {
        $payment = $this->payment(today()->addDays(5), true, false, false, false);

        $this->assertSame(PaymentStatus::PARTIAL, $payment->status());
    }

    #[Test]
    public function un_pago_sin_servicios_pagados_y_vencido_reporta_vencido(): void
    {
        $payment = $this->payment(today()->subDay(), false, false, false, false);

        $this->assertSame(PaymentStatus::OVERDUE, $payment->status());
    }

    #[Test]
    public function un_pago_sin_servicios_pagados_y_a_tiempo_reporta_pendiente(): void
    {
        $payment = $this->payment(today()->addDay(), false, false, false, false);

        $this->assertSame(PaymentStatus::PENDING, $payment->status());
    }

    #[Test]
    public function un_pago_que_vence_hoy_no_reporta_vencido(): void
    {
        // La fecha se guarda a las 00:00, asi que comparar contra la hora actual
        // marcaba como vencido un pago que todavia no vencio.
        $payment = $this->payment(today(), false, false, false, false);

        $this->assertSame(PaymentStatus::PENDING, $payment->status());
    }

    #[Test]
    public function un_pago_parcial_que_vence_hoy_no_reporta_vencido(): void
    {
        $payment = $this->payment(today(), true, false, false, false);

        $this->assertSame(PaymentStatus::PARTIAL, $payment->status());
    }
}