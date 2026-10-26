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
 * carta con un canon mal cobrado.
 *
 * CAMBIO EN ESTE ARCHIVO
 * Las pruebas de requiresWrittenAgreement() se eliminaron. Ese metodo hacia del
 * exceso una condicion para aplicar el reajuste, y la revision del modulo cambio
 * esa parte: el articulo 20 lo formula como limite pero la jurisprudencia que
 * fija si el IPC es tope o parametro no pudo verificarse, de modo que excederlo
 * avisa y no bloquea. Un metodo que devuelve true cuando se excede el IPC ya no
 * describe una obligacion legal sino un dato informativo, y el nombre sugeria lo
 * primero.
 */
class RentAdjustmentTest extends TestCase
{
    private function adjustment(
        float $previousRent = 1_000_000,
        float $requestedRent = 1_092_800,
        float $percentage = 9.28,
        int $year = 2025,
        ?float $anchorRent = null,
    ): RentAdjustment {
        return RentAdjustment::fromFrozen(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            legalCapRent: round($previousRent * (1 + $percentage / 100)),
            ipcPercentage: $percentage,
            ipcYear: $year,
            ipcSourceName: 'DANE',
            ipcSourceUrl: 'https://www.dane.gov.co/ipc',
            anchorRent: $anchorRent ?? $previousRent,
        );
    }

    #[Test]
    public function el_tope_es_el_canon_porcentual_al_ipc(): void
    {
        $adjustment = $this->adjustment();

        $this->assertSame(1_092_800.0, $adjustment->legalCapRent);
        $this->assertSame(0.0, $adjustment->excessOverCap());
        $this->assertFalse($adjustment->exceedsCap());
    }

    #[Test]
    public function un_incremento_dentro_del_ipc_no_deja_exceso(): void
    {
        $adjustment = $this->adjustment(requestedRent: 1_050_000);

        $this->assertFalse($adjustment->exceedsCap());
        $this->assertSame(50_000.0, $adjustment->increase());
        $this->assertSame(5.0, $adjustment->increasePercentage());
    }

    #[Test]
    public function un_incremento_sobre_el_ipc_se_reporta_como_exceso(): void
    {
        // 15 % pedido con un IPC de 9,28 %: el tope es 1.092.800 y el exceso
        // son 357.200 pesos. Se informa, pero no bloquea la aplicacion.
        $adjustment = $this->adjustment(requestedRent: 1_450_000);

        $this->assertTrue($adjustment->exceedsCap());
        $this->assertSame(357_200.0, $adjustment->excessOverCap());
    }

    #[Test]
    public function una_rebaja_no_pasa_por_el_tope_del_ipc(): void
    {
        // Bajar el canon es una rebaja, no un reajuste. El articulo 20 limita
        // el incremento, no la disminucion, asi que no debe marcarse como
        // exceso.
        $adjustment = $this->adjustment(requestedRent: 800_000);

        $this->assertFalse($adjustment->isIncrease());
        $this->assertFalse($adjustment->exceedsCap());
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

    #[Test]
    public function pactar_menos_que_el_ipc_no_mueve_el_tope(): void
    {
        // El articulo 20 dice "hasta", asi que acordar menos que el IPC es
        // legitimo. Lo que cambia es la cifra ACORDADA, no el tope: el tope sigue
        // siendo el que fija el IPC.
        $ipc = new \App\Models\IpcRate([
            'year' => 2025,
            'percentage' => 9.28,
            'source_name' => 'DANE',
            'source_url' => 'https://www.dane.gov.co/ipc',
        ]);

        $adjustment = RentAdjustment::fromIpc(
            previousRent: 1_000_000,
            requestedRent: 1_030_000,
            ipc: $ipc,
            pactadoPercentage: 3.0,
        );

        $this->assertSame(1_092_800.0, $adjustment->legalCapRent, 'el tope lo fija el IPC');
        $this->assertSame(3.0, $adjustment->appliedPercentage);
        $this->assertSame(9.28, $adjustment->legalPercentage);
        $this->assertTrue($adjustment->hasPactadoCap());
        $this->assertFalse($adjustment->exceedsCap());
    }

    #[Test]
    public function el_acumulado_se_mide_desde_el_canon_base_y_no_desde_el_anterior(): void
    {
        // Esta es la distincion que mas confunde al inquilino. El incremento de
        // ESTE reajuste es sobre el canon que rigia; el acumulado se mide contra
        // el canon original del contrato.
        $ajustePrimero = $this->adjustment(
            previousRent: 900_000,
            requestedRent: 983_520,
            percentage: 9.28,
            anchorRent: 900_000,
        );

        $this->assertSame(9.28, $ajustePrimero->increasePercentage());
        $this->assertSame(9.28, $ajustePrimero->cumulativeIncreasePercentage());
    }

    #[Test]
    public function el_acumulado_difiere_del_incremento_del_ultimo_ajuste(): void
    {
        // Canon base 900.000, IPC 9,28 % y despues 5,20 %. El segundo incremento
        // es del 5,20 % sobre el canon que rigia, pero el acumulado es la
        // composicion de los dos.
        $ajusteSegundo = $this->adjustment(
            previousRent: 983_520,
            requestedRent: 1_034_663,
            percentage: 5.20,
            year: 2024,
            anchorRent: 900_000,
        );

        $this->assertSame(5.20, $ajusteSegundo->increasePercentage());
        $this->assertSame(14.96, $ajusteSegundo->cumulativeIncreasePercentage());
    }
}
