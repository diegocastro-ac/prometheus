<?php

namespace Tests\Feature;

use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * El recalculo guarda la cadena antes de escribir y puede devolverla.
 *
 * Lo que se fija aqui: el memento refleja el estado ANTERIOR, la simulacion no
 * escribe y la restauracion devuelve el canon y todos los campos derivados.
 */
class AdjustmentChainMementoTest extends DocumentTestCase
{
    private function recalculator(): AdjustmentChainRecalculator
    {
        return app(AdjustmentChainRecalculator::class);
    }

    /**
     * Un alquiler con la cadena deliberadamente desalineada respecto de su
     * ancla. El recalculo tiene que corregirla; el memento debe conservar los
     * valores viejos.
     *
     * @return array{0: Rental, 1: RentAdjustment}
     */
    private function staleChain(): array
    {
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Alquiler memento',
            'monthly_amount' => 900_000,
            'anchor_monthly_amount' => 900_000,
            'start_date' => today()->subYears(2)->toDateString(),
            'end_date' => today()->addYear()->toDateString(),
            'anchor_established_on' => today()->subYears(2)->toDateString(),
        ]);

        $adjustment = RentAdjustment::factory()->applied()->create([
            'rental_id' => $rental->id,
            'user_id' => $this->user->id,
            'sequence' => 9,
            'anchor_rent' => 111_111,
            'previous_rent' => 111_111,
            'legal_cap_rent' => 222_222,
            'excess_over_cap' => 33_333,
            'new_rent' => 945_000,
            'legal_pct' => 5.0,
            'ipc_percentage' => 5.0,
            'applied_pct' => 5.0,
            'effective_from' => today()->setYear(2026)->setMonth(10)->setDay(15)->toDateString(),
        ]);

        return [$rental->refresh(), $adjustment->refresh()];
    }

    #[Test]
    public function el_recalculo_devuelve_el_estado_anterior_al_cambio(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $result = $this->recalculator()->recalculate($rental);
        $snapshot = $result['memento']->adjustment((int) $adjustment->id);

        $this->assertSame(1, $result['touched']);
        $this->assertSame(900_000.0, $result['memento']->monthlyAmount);

        $this->assertNotNull($snapshot);
        $this->assertSame(9, $snapshot->sequence);
        $this->assertSame(111_111.0, $snapshot->anchorRent);
        $this->assertSame(111_111.0, $snapshot->previousRent);
        $this->assertSame(222_222.0, $snapshot->legalCapRent);
        $this->assertSame(33_333.0, $snapshot->excessOverCap);

        // El modelo ya quedo corregido: el memento es la unica copia del antes.
        $this->assertSame(1, (int) $adjustment->refresh()->sequence);
    }

    #[Test]
    public function restaurar_devuelve_el_canon_y_los_campos_derivados(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $memento = $this->recalculator()->recalculate($rental)['memento'];

        $this->assertTrue($this->recalculator()->restore($memento));

        $adjustment->refresh();
        $rental->refresh();

        $this->assertSame(9, (int) $adjustment->sequence);
        $this->assertSame(111_111.0, (float) $adjustment->anchor_rent);
        $this->assertSame(111_111.0, (float) $adjustment->previous_rent);
        $this->assertSame(222_222.0, (float) $adjustment->legal_cap_rent);
        $this->assertSame(33_333.0, (float) $adjustment->excess_over_cap);
        $this->assertSame(900_000.0, (float) $rental->monthly_amount);
    }

    #[Test]
    public function la_simulacion_no_escribe_y_solo_reporta_lo_que_cambiaria(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $preview = $this->recalculator()->preview($rental);

        $this->assertSame(1, $preview['touched']);
        $this->assertCount(1, $preview['changes']);
        $this->assertSame(1, $preview['changes'][0]['after']['sequence']);

        // Nada se escribio.
        $this->assertSame(9, (int) $adjustment->refresh()->sequence);
        $this->assertSame(900_000.0, (float) $rental->refresh()->monthly_amount);

        // Una vez corregida, la simulacion ya no reporta cambios.
        $this->recalculator()->recalculate($rental);

        $clean = $this->recalculator()->preview($rental->refresh());

        $this->assertSame(0, $clean['touched']);
        $this->assertSame([], $clean['changes']);
    }

    #[Test]
    public function un_memento_de_otro_alquiler_se_rechaza(): void
    {
        [$rental] = $this->staleChain();

        $otro = Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => 500_000,
            'anchor_monthly_amount' => 500_000,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $otro->restoreAdjustmentChain($rental->createAdjustmentChainMemento());
    }
}
