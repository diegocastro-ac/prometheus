<?php

namespace Tests\Feature;

use App\Models\RentAdjustment;
use App\Models\Rental;
use PHPUnit\Framework\Attributes\Test;

/**
 * La simulacion del comando de recalculo no puede escribir.
 *
 * Antes, --dry-run solo imprimia la cadena actual y, cuando se usaba sin
 * --rental, seguia reprocesando de verdad. Estas pruebas fijan que la bandera
 * sea segura en los dos modos y que el recálculo normal siga funcionando.
 */
class RecalculateAdjustmentChainsCommandTest extends DocumentTestCase
{
    /**
     * @return array{0: Rental, 1: RentAdjustment}
     */
    private function staleChain(): array
    {
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Alquiler comando',
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
    public function la_simulacion_de_un_alquiler_muestra_el_cambio_sin_escribir(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $this->artisan('rent-adjustments:recalculate', [
            '--rental' => $rental->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Simulacion: no se escribira ningun cambio.')
            ->expectsOutputToContain('Cambios previstos:')
            ->assertSuccessful();

        $this->assertSame(9, (int) $adjustment->refresh()->sequence);
        $this->assertSame(900_000.0, (float) $rental->refresh()->monthly_amount);
    }

    #[Test]
    public function la_simulacion_global_tampoco_escribe(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $this->artisan('rent-adjustments:recalculate', [
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Simulacion: no se escribio ningun cambio.')
            ->assertSuccessful();

        $this->assertSame(9, (int) $adjustment->refresh()->sequence);
        $this->assertSame(900_000.0, (float) $rental->refresh()->monthly_amount);
    }

    #[Test]
    public function sin_simulacion_el_comando_reprocesa_la_cadena(): void
    {
        [$rental, $adjustment] = $this->staleChain();

        $this->artisan('rent-adjustments:recalculate', [
            '--rental' => $rental->id,
        ])->assertSuccessful();

        $this->assertSame(1, (int) $adjustment->refresh()->sequence);
        $this->assertSame(945_000.0, (float) $rental->refresh()->monthly_amount);
    }
}
