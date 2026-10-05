<?php

namespace Tests\Feature;

use App\Models\IpcRate;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\RentAdjustmentService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Calculo y aplicacion del reajuste.
 *
 * El tope se decide aqui y no en el formulario ni en el documento. Estas
 * pruebas fijan esa regla para que ningun cambio de interfaz pueda alterarla sin
 * que se note.
 */
class RentAdjustmentServiceTest extends DocumentTestCase
{
    private function service(): RentAdjustmentService
    {
        return app(RentAdjustmentService::class);
    }

    private function rental(float $monthlyAmount = 1_000_000): Rental
    {
        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => $monthlyAmount,
            'name' => 'Apartamento centro',
        ]);
    }

    #[Test]
    public function un_reajuste_dentro_del_ipc_no_deja_exceso(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(1_000_000),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame(1_000_000.0, (float) $adjustment->previous_rent);
        $this->assertSame(1_092_800.0, (float) $adjustment->legal_cap_rent);
        $this->assertSame(0.0, (float) $adjustment->excess_over_cap);
        $this->assertFalse($adjustment->requires_written_agreement);
        $this->assertSame(2025, $adjustment->ipc_year);
        $this->assertSame(9.28, (float) $adjustment->ipc_percentage);
    }

    #[Test]
    public function el_anio_del_ipc_es_el_anterior_al_de_vigencia(): void
    {
        // El articulo 20 obliga a usar el ano calendario inmediatamente
        // anterior. Un reajuste que empieza en 2026 se calcula con el IPC de
        // 2025, no con el de 2026.
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame(2025, $adjustment->ipc_year);
        $this->assertSame(9.28, (float) $adjustment->ipc_percentage);
    }

    #[Test]
    public function un_ipc_de_otro_ano_se_rechaza_sin_confirmacion(): void
    {
        // El formulario ofrece el ultimo IPC disponible, que puede no ser el que
        // corresponde al ano de vigencia. Si se acepta en silencio, la carta
        // imprime un IPC que el articulo 20 no permite.
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fija el IPC de 2025');

        $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
            ipcYear: 2026,
        );
    }

    #[Test]
    public function apartarse_del_ano_legal_exige_dejar_escrito_el_motivo(): void
    {
        // Confirmar el apartamiento sin explicar por que deja el registro sin
        // rastro, que es justo lo que la regla busca evitar.
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exige dejar escrito el motivo');

        $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
            ipcYear: 2026,
            notes: '   ',
            ipcOutsideStatutoryYear: true,
        );
    }

    #[Test]
    public function apartarse_del_ano_legal_confirmado_y_explicado_si_se_registra(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(),
            newRent: 1_041_500,
            effectiveFrom: today()->startOfYear(),
            ipcYear: 2026,
            notes: 'El IPC de 2025 fue revisado por el DANE.',
            ipcOutsideStatutoryYear: true,
        );

        $this->assertSame(2026, $adjustment->ipc_year);
        $this->assertSame(4.15, (float) $adjustment->ipc_percentage);
        $this->assertDatabaseHas('rent_adjustments', [
            'id' => $adjustment->id,
            'ipc_year' => 2026,
        ]);
    }

    #[Test]
    public function confirmar_el_apartamiento_no_habilita_un_ipc_inventado(): void
    {
        // La excepcion es sobre el ano, no sobre los datos: si el ano indicado no
        // existe, sigue sin poder calcularse el tope.
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay IPC registrado para 2031');

        $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
            ipcYear: 2031,
            notes: 'Motivo escrito.',
            ipcOutsideStatutoryYear: true,
        );
    }

    #[Test]
    public function sin_ipc_explicito_no_hay_nada_que_confirmar(): void
    {
        // El servicio ya deriva el ano que manda la ley, asi que omitirlo debe
        // seguir siendo la via normal y no una confirmacion pendiente.
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame(2025, $adjustment->ipc_year);
    }

    #[Test]
    public function un_reajuste_sobre_el_ipc_marca_el_exceso_y_exige_acuerdo(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(1_000_000),
            newRent: 1_450_000,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertTrue($adjustment->exceedsCap());
        $this->assertTrue($adjustment->requires_written_agreement);
        $this->assertSame(357_200.0, (float) $adjustment->excess_over_cap);
    }

    #[Test]
    public function el_ipc_queda_congelado_en_el_ajuste(): void
    {
        // Si despues se corrige el dato del DANE, una carta ya emitida no puede
        // cambiar de contenido. Por eso el ajuste guarda el año y el porcentaje
        // con que se calculo.
        $ipc = IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $ipc->update(['percentage' => 3.00]);

        $adjusted = $adjustment->fresh();

        $this->assertSame(9.28, (float) $adjusted->ipc_percentage);
        $this->assertSame(1_092_800.0, (float) $adjusted->legal_cap_rent);
    }

    #[Test]
    public function sin_ipc_registrado_no_se_calcula_el_tope(): void
    {
        // Sin el dato oficial no hay tope legal que probar. Es preferible fallar
        // a inventar un porcentaje.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay IPC registrado');

        $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );
    }

    #[Test]
    public function un_canon_reajustado_de_cero_se_rechaza(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $this->expectException(RuntimeException::class);

        $this->service()->record(
            rental: $this->rental(),
            newRent: 0,
            effectiveFrom: today()->startOfYear(),
        );
    }

    #[Test]
    public function el_reajuste_se_aplica_al_canon_del_alquiler(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->service()->applyToRental($adjustment);

        $this->assertSame(1_092_800.0, (float) $rental->refresh()->monthly_amount);
    }

    #[Test]
    public function un_reajuste_sobre_el_tope_no_se_aplica_sin_acuerdo_escrito(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_450_000,
            effectiveFrom: today()->startOfYear(),
        );

        // El exceso existe y se registra, pero el canon no cambia: pasarlo sin
        // acuerdo escrito seria el fallo que el articulo 20 evita.
        $this->expectException(RuntimeException::class);

        $this->service()->applyToRental($adjustment);
    }

    #[Test]
    public function con_acuerdo_escrito_el_reajuste_sobre_el_tope_si_se_aplica(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_450_000,
            effectiveFrom: today()->startOfYear(),
            notes: 'Acuerdo escrito firmado por las dos partes.',
        );

        $this->service()->applyToRental($adjustment);

        $this->assertSame(1_450_000.0, (float) $rental->refresh()->monthly_amount);
    }

    #[Test]
    public function marcar_comunicado_deja_constancia_de_la_fecha(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertNull($adjustment->notified_on);

        $this->service()->markNotified($adjustment, today()->addMonths(2));

        $this->assertSame(
            today()->addMonths(2)->toDateString(),
            $adjustment->refresh()->notified_on->toDateString(),
        );
    }

    #[Test]
    public function el_reajuste_pertenece_al_arrendador_del_alquiler(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame($this->user->id, $adjustment->user_id);
    }

    #[Test]
    public function se_puede_registrar_un_reajuste_disminuyendo_el_canon(): void
    {
        // Una rebaja no pasa por el tope del IPC, que solo limita el incremento.
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(1_000_000),
            newRent: 850_000,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertFalse($adjustment->exceedsCap());
        $this->assertFalse($adjustment->requires_written_agreement);
        $this->assertSame(0.0, (float) $adjustment->excess_over_cap);

        $this->service()->applyToRental($adjustment);

        $this->assertSame(850_000.0, (float) $adjustment->rental->refresh()->monthly_amount);
    }

    #[Test]
    public function el_ajuste_se_relaciona_con_el_rental_desde_el_modelo(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $this->service()->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertCount(1, $rental->rentAdjustments);
        $this->assertInstanceOf(RentAdjustment::class, $rental->rentAdjustments->first());
    }

    #[Test]
    public function el_ajuste_se_reconstruye_desde_el_valor_object(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $adjustment = $this->service()->record(
            rental: $this->rental(1_000_000),
            newRent: 1_450_000,
            effectiveFrom: today()->startOfYear(),
        );

        $value = $adjustment->fresh()->toValueObject();

        $this->assertNotNull($value);
        $this->assertTrue($value->exceedsCap());
        $this->assertSame(1_092_800.0, $value->legalCapRent);
        $this->assertSame(357_200.0, $value->excessOverCap());
    }
}
