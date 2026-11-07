<?php

namespace Tests\Feature;

use App\Enums\AdjustmentState;
use App\Models\RentAdjustment;
use PHPUnit\Framework\Attributes\Test;

/**
 * El modelo expone las capacidades de su estado sin que las pantallas tengan que
 * conocer la matriz. La derivacion por fechas no cambia: estas pruebas fijan el
 * comportamiento observable que ya consumian el servicio y Filament.
 */
class AdjustmentStateTest extends DocumentTestCase
{
    #[Test]
    public function un_reajuste_registrado_puede_notificarse_y_editarse_pero_no_aplicarse(): void
    {
        $adjustment = RentAdjustment::factory()->create();

        $this->assertSame(AdjustmentState::Recorded, $adjustment->state());
        $this->assertTrue($adjustment->canNotify());
        $this->assertFalse($adjustment->canApply());
        $this->assertTrue($adjustment->canEdit());
        $this->assertFalse($adjustment->countsTowardsChain());
    }

    #[Test]
    public function un_reajuste_notificado_puede_aplicarse_pero_no_volver_a_notificarse(): void
    {
        $adjustment = RentAdjustment::factory()->notified()->create();

        $this->assertSame(AdjustmentState::Notified, $adjustment->state());
        $this->assertFalse($adjustment->canNotify());
        $this->assertTrue($adjustment->canApply());
        $this->assertTrue($adjustment->canEdit());
        $this->assertFalse($adjustment->countsTowardsChain());
    }

    #[Test]
    public function un_reajuste_aplicado_cierra_el_ciclo_y_cuenta_para_la_cadena(): void
    {
        $adjustment = RentAdjustment::factory()->applied()->create();

        $this->assertSame(AdjustmentState::Applied, $adjustment->state());
        $this->assertFalse($adjustment->canNotify());
        $this->assertFalse($adjustment->canApply());
        $this->assertFalse($adjustment->canEdit());
        $this->assertTrue($adjustment->countsTowardsChain());
    }
}
