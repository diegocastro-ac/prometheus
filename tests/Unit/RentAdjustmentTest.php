<?php

namespace Tests\Unit;

use App\ValueObjects\RentAdjustment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reglas del articulo 20 de la Ley 820 de 2003.
 *
 * Se prueban como value object y sin base de datos: si el tope dependiera de la
 * base, un error de calculo pasaria inadvertido hasta que se emitiera una
 * carta con un canon mal Cobrado.
 */
class RentAdjustmentTest extends TestCase
{
    private function adjustment(
        float $previousRent = 1_000_000,
        float $requestedRent = 1_092_800,
        float $percentage = 9.28,
        int $year = 2025,
    ): RentAdjustment {
        return RentAdjustment::fromFrozen(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            legalCapRent: round($previousRent * (1 + $percentage / 100)),
            ipcPercentage: $percentage,
            ipcYear: $year,
            ipcSourceName: 'DANE',
            ipcSourceUrl: 'https://www.dane.gov.co/ipc',
        );
    }

    #[Test]
    public function el_tope_es_el_canon_porcentual_al_ipc(): void
    {
        $adjustment = $this->adjustment();

        $this->assertSame(1_092_800.0, $adjustment->legalCapRent);
        $this->assertSame(0.0, $adjustment->excessOverCap());
        $this->assertFalse($adjustment->exceedsCap());
        $this->assertFalse($adjustment->requiresWrittenAgreement());
    }

    #[Test]
    public function un_incremento_dentro_del_ipc_no_pide_acuerdo_escrito(): void
    {
        $adjustment = $this->adjustment(requestedRent: 1_050_000);

        $this->assertFalse($adjustment->exceedsCap());
        $this->assertFalse($adjustment->requiresWrittenAgreement());
        $this->assertSame(50_000.0, $adjustment->increase());
        $this->assertSame(5.0, $adjustment->increasePercentage());
    }

    #[Test]
    public function un_incremento_sobre_el_ipc_se_reporta_como_exceso(): void
    {
        // 15 % pedido con un IPC de 9,28 %: el tope es 1.092.800 y el exceso
        // son 357.200 pesos.
        $adjustment = $this->adjustment(requestedRent: 1_450_000);

        $this->assertTrue($adjustment->exceedsCap());
        $this->assertTrue($adjustment->requiresWrittenAgreement());
        $this->assertSame(357_200.0, $adjustment->excessOverCap());
    }

    #[Test]
    public function una_rebaja_no_pasa_por_el_tope_del_ipc(): void
    {
        // Bajar el canon es una rebaja, no un reajuste. El articulo 20 limita
        // el incremento, no la disminucion, asi que no debe marcarse como
        // exceso ni pedir acuerdo escrito.
        $adjustment = $this->adjustment(requestedRent: 800_000);

        $this->assertFalse($adjustment->isIncrease());
        $this->assertFalse($adjustment->exceedsCap());
        $this->assertFalse($adjustment->requiresWrittenAgreement());
        $this->assertSame(0.0, $adjustment->excessOverCap());
        $this->assertSame(-200_000.0, $adjustment->increase());
    }

    #[Test]
    public function el_porcentaje_de_incremento_no_se_divide_entre_cero(): void
    {
        $adjustment = $this->adjustment(previousRent: 0, requestedRent: 500_000);

        $this->assertSame(0.0, $adjustment->increasePercentage());
    }

    #[Test]
    public function el_factor_del_ipc_se_muestra_con_cuatro_decimales(): void
    {
        $this->assertSame('1,0928', $this->adjustment(percentage: 9.28)->ipcFactor());
    }

    #[Test]
    public function from_ipc_calcula_el_tope_desde_el_porcentaje(): void
    {
        $ipc = new \App\Models\IpcRate([
            'year' => 2025,
            'percentage' => 9.28,
            'source_name' => 'DANE',
            'source_url' => 'https://www.dane.gov.co/ipc',
        ]);

        $adjustment = RentAdjustment::fromIpc(
            previousRent: 800_000,
            requestedRent: 874_240,
            ipc: $ipc,
        );

        $this->assertSame(874_240.0, $adjustment->legalCapRent);
        $this->assertSame(0.0, $adjustment->excessOverCap());
        $this->assertSame(2025, $adjustment->ipcYear);
    }
}
