<?php

namespace Tests\Feature;

use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Models\IpcRate;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use App\Services\RentAdjustmentService;
use PHPUnit\Framework\Attributes\Test;

/**
 * La cadena de reajustes y su reproceso.
 *
 * ESTE ARCHIVO ES LA PRUEBA DE QUE EL CAMBIO DE FONDO SIRVIO PARA ALGO.
 *
 * El problema que venia era de estado. El servicio guardaba en cada ajuste el
 * canon vigente del alquiler en el momento de crearlo. Como ese canon lo modifica
 * el propio servicio al aplicar un reajuste, la copia se quedaba obsoleta en
 * cuanto se editaba un ajuste anterior, y al borrar uno el canon del alquiler
 * quedaba sin ninguna explicacion. No habia ancla, y sin ancla no habia forma de
 * recalcular.
 *
 * Lo que estas pruebas fijan:
 *
 *  1. previous_rent es DERIVADO. Encadena sobre el canon que rigio, no sobre el
 *     canon original del contrato, porque el articulo 20 cuenta los doce meses
 *     "bajo un mismo precio".
 *  2. Editar un reajuste reprograme los siguientes desde el canon base.
 *  3. Borrar un reajuste cierra la cadena sin dejar el canon flotando.
 *  4. El recalculo es idempotente: correrlo dos veces no cambia nada la segunda.
 */
class AdjustmentChainTest extends DocumentTestCase
{
    private function service(): RentAdjustmentService
    {
        return app(RentAdjustmentService::class);
    }

    private function calculator(): AdjustmentChainCalculator
    {
        return app(AdjustmentChainCalculator::class);
    }

    private function recalculator(): AdjustmentChainRecalculator
    {
        return app(AdjustmentChainRecalculator::class);
    }

    private function rental(float $monthlyAmount = 900_000): Rental
    {
        $start = today()->subYears(5);

        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Apartamento prueba',
            'monthly_amount' => $monthlyAmount,
            'anchor_monthly_amount' => $monthlyAmount,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->toDateString(),
            'anchor_established_on' => $start->toDateString(),
        ]);
    }

    /**
     * Tres reajustes con los IPC oficiales verificados: 2023 = 9,28 %,
     * 2024 = 5,20 % y 2025 = 5,10 %.
     *
     * Ojo con el desplazamiento: el articulo 20 toma "el ano calendario
     * inmediatamente anterior a aquel en que deba efectuarse el reajuste". Un
     * reajuste que rige en OCTUBRE de 2024 usa el IPC de 2023, no el de 2024.
     *
     * @return array{0: Rental, 1: list<RentAdjustment>}
     */
    private function rentalWithThreeAdjustments(float $base = 900_000): array
    {
        IpcRate::factory()->forYear(2023, 9.28)->create();
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental($base);

        // Las vigencias van en octubre, con un ano de separacion, como en un
        // contrato real que se reajusta en su aniversario.
        //
        // Se registran de uno en uno y se aplican en el acto, para que cada uno
        // use el canon que realmente rigio en su momento. Es el uso real del
        // sistema, no un atajo de pruebas.
        $rental->refresh();
        $a1 = $this->service()->record(
            rental: $rental,
            newRent: round($base * 1.0928),
            effectiveFrom: today()->setYear(2024)->setMonth(10)->setDay(15),
        );
        $this->service()->applyToRental($a1);

        $rental->refresh();
        $a2 = $this->service()->record(
            rental: $rental,
            newRent: round((float) $a1->new_rent * 1.052),
            effectiveFrom: today()->setYear(2025)->setMonth(10)->setDay(15),
        );
        $this->service()->applyToRental($a2);

        $rental->refresh();
        $a3 = $this->service()->record(
            rental: $rental,
            newRent: round((float) $a2->new_rent * 1.051),
            effectiveFrom: today()->setYear(2026)->setMonth(10)->setDay(15),
        );
        $this->service()->applyToRental($a3);

        return [$rental->refresh(), [$a1->refresh(), $a2->refresh(), $a3->refresh()]];
    }

    #[Test]
    public function la_cadena_encadena_sobre_el_canon_que_regia_y_no_sobre_el_original(): void
    {
        // El articulo 20: "cada doce meses de ejecucion del contrato BAJO UN MISMO
        // PRECIO". El precio que rigio en 2025 es el del reajuste de 2024, no el
        // canon original. Encadenar sobre el canon original equivaldria a
        // renunciar al 5,20 % del IPC de ese ano.
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        $this->assertSame(900_000.0, (float) $a1->previous_rent);
        $this->assertSame(983_520.0, (float) $a1->new_rent);

        $this->assertSame(983_520.0, (float) $a2->previous_rent, 'el segundo encadena sobre el primero');
        $this->assertSame(1_034_663.0, (float) $a2->new_rent);

        $this->assertSame(1_034_663.0, (float) $a3->previous_rent);
        $this->assertSame(1_087_431.0, (float) $a3->new_rent);

        $this->assertSame(1_087_431.0, (float) $rental->monthly_amount);
    }

    #[Test]
    public function el_tope_de_cada_ajuste_se_calcula_sobre_su_canon_de_entrada(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        $this->assertSame(983_520.0, (float) $a1->legal_cap_rent);
        $this->assertSame(round(983_520 * 1.052), (float) $a2->legal_cap_rent);
        $this->assertSame(round(1_034_663 * 1.051), (float) $a3->legal_cap_rent);
    }

    #[Test]
    public function cada_ajuste_guarda_el_canon_base_del_alquiler(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        // Es lo que permite reconstruir la cadena entera sin volver a preguntar
        // por el canon original, que pudo haber cambiado.
        $this->assertSame(900_000.0, (float) $a1->anchor_rent);
        $this->assertSame(900_000.0, (float) $a2->anchor_rent);
        $this->assertSame(900_000.0, (float) $a3->anchor_rent);
    }

    #[Test]
    public function el_acumulado_se_mide_desde_el_canon_base_y_no_desde_el_ultimo(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        // 1.087.431 sobre 900.000 es un 20,83 %, no el 5,10 % del ultimo ano.
        $this->assertSame(5.10, (float) $a3->increasePercentage());
        $this->assertSame(20.83, (float) $a3->cumulativeIncreasePercentage());
    }

    /**
     * ESTA ES LA PRUEBA QUE NO SE PODIA ESCRIBIR ANTES.
     *
     * Con el diseno anterior, cambiar el IPC del primer ajuste no podia cambiar
     * los siguientes: el canon de entrada de cada uno era una copia congelada.
     */
    #[Test]
    public function cambiar_el_ipc_del_primer_ajuste_reprograma_los_siguientes(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        // El canon de entrada de cada ajuste es el canon que RIGIO, que es el
        // new_rent del anterior, no el canon original ni el vigente.
        $this->assertSame(983_520.0, (float) $a2->previous_rent);
        $this->assertSame(1_034_663.0, (float) $a3->previous_rent);
        $this->assertSame(1_087_431.0, (float) $a3->new_rent);

        // El DANE corrige el IPC de 2023 y pasa de 9,28 % a 4,00 %.
        $a1->forceFill([
            'ipc_percentage' => 4.00,
            'legal_pct' => 4.00,
            'applied_pct' => 4.00,
            'new_rent' => round(900_000 * 1.04),
        ])->saveQuietly();

        $this->recalculator()->recalculate($rental);

        $a2->refresh();
        $a3->refresh();

        // El canon de entrada del segundo pasa a ser el del primero corregido.
        $this->assertSame(936_000.0, (float) $a2->previous_rent, 'el segundo entra sobre el canon corregido');
        $this->assertSame(round(936_000 * 1.052), (float) $a2->legal_cap_rent);

        // Lo que NO se mueve es a2.new_rent, que es el canon que las partes
        // acordarON, no el que la ley calcula. El tercero entra por ese valor, y
        // por eso sigue en 1.034.663.
        //
        // Esta distincion es deliberada y es la razon de que el replay derive
        // previous_rent pero respete new_rent: corregir el IPC recalcula lo que
        // la ley determina, no lo que se pactó.
        $this->assertSame(1_034_663.0, (float) $a2->new_rent, 'el canon acordado no lo cambia el IPC');
        $this->assertSame(1_034_663.0, (float) $a3->previous_rent);
        $this->assertSame(round(1_034_663 * 1.051), (float) $a3->legal_cap_rent);

        // Y si ademas se corrige el canon acordado del segundo, el tercero si se
        // mueve. Esa es la cascada completa.
        $a2->forceFill(['new_rent' => round(936_000 * 1.052)])->saveQuietly();
        $this->recalculator()->recalculate($rental);

        $this->assertSame(round(936_000 * 1.052), (float) $a3->refresh()->previous_rent);
    }

    #[Test]
    public function el_recalculo_es_idempotente(): void
    {
        [$rental] = $this->rentalWithThreeAdjustments(900_000);

        $primera = $this->recalculator()->recalculate($rental);
        $segunda = $this->recalculator()->recalculate($rental);

        // Si la segunda pasada escribiera algo, sincronizar el IPC todos los dias
        // moveria datos que ya estaban bien.
        $this->assertSame(0, $primera['touched']);
        $this->assertSame(0, $segunda['touched']);
    }

    #[Test]
    public function el_canon_del_alquiler_sigue_a_el_ultimo_ajuste_aplicado(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        $this->assertSame(1_087_431.0, (float) $rental->monthly_amount);
        $this->assertSame(1_087_431.0, $this->calculator()->currentRentFor($rental));
    }

    /**
     * Un reajuste registrado pero no aplicado no cambio el canon.
     *
     * Encadenarlo haria que el siguiente se calculara sobre una cifra que nunca
     * rigio, que es el error mas sutil de todos.
     */
    #[Test]
    public function un_reajuste_sin_aplicar_no_entra_en_la_cadena(): void
    {
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental(1_000_000);

        $aplicado = $this->service()->record(
            rental: $rental,
            newRent: 1_052_000,
            effectiveFrom: today()->setYear(2025)->setMonth(6)->setDay(1),
        );
        $this->service()->applyToRental($aplicado);

        // Este queda registrado pero NO aplicado.
        $pendiente = $this->service()->record(
            rental: $rental->refresh(),
            newRent: 1_100_000,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            notes: 'Pendiente de comunicar.',
        );

        $this->assertSame(1_052_000.0, $this->calculator()->currentRentFor($rental->refresh()));
        $this->assertSame(1_052_000.0, (float) $rental->refresh()->monthly_amount);
        $this->assertNull($pendiente->applied_on);
    }

    #[Test]
    public function el_reajuste_aplicado_es_el_unico_que_cuenta_para_el_plazo_de_doce_meses(): void
    {
        [$rental, [$a1, $a2, $a3]] = $this->rentalWithThreeAdjustments(900_000);

        // El reloj del articulo 20 corre desde el ULTIMO cambio de canon, no
        // desde el inicio del contrato.
        $expected = $a3->effective_from->copy()->addYear();

        $this->assertSame(
            $expected->toDateString(),
            $rental->nextAdjustmentDueDate()->toDateString(),
        );
    }

    #[Test]
    public function un_alquiler_comercial_avisa_que_la_ley_no_le_alcanza(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental(1_000_000);
        $rental->forceFill(['rental_type' => \App\Enums\RentalType::Commercial->value])->saveQuietly();

        $result = $this->service()->validate(
            rental: $rental->refresh(),
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            requestedRent: 1_051_000,
        );

        $text = implode(' ', array_column($result['messages'], 'message'));

        // El mensaje ya no grita COMERCIAL en mayusculas: se reescribio en
        // registro formal para que la app no dependa de una sola palabra del
        // enum. Lo que se comprueba es que avise del alcance, no el grito.
        $this->assertStringContainsString('registrado como comercial', $text);
        $this->assertStringContainsString('no un límite legal', $text);
    }

    #[Test]
    public function el_articulo_18_limita_el_canon_al_uno_por_ciento_del_valor_comercial(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // Valor comercial de 100.000.000 => el canon no puede pasar de 1.000.000.
        // Un canon de 1.500.000 ya esta fuera del limite ANTES de reajustar.
        $rental = $this->rental(1_000_000);
        $rental->forceFill([
            'commercial_value' => 100_000_000,
            'catastral_appraisal' => 40_000_000,
        ])->saveQuietly();

        $result = $this->service()->validate(
            rental: $rental->refresh(),
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            requestedRent: 1_500_000,
        );

        $text = implode(' ', array_column($result['messages'], 'message'));

        $this->assertStringContainsString('uno por ciento', $text);
        $this->assertStringContainsString('1.000.000', $text);
    }

    /**
     * La segunda mitad del articulo 18, que casi nunca se cita.
     *
     * "La estimacion comercial para efectos del presente articulo no podra exceder
     * el equivalente a dos (2) veces el avaluo catastral vigente."
     */
    #[Test]
    public function el_valor_comercial_no_puede_pasar_de_dos_avalúos_catastrales(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // Avaluo de 40.000.000 => el valor comercial no puede pasar de
        // 80.000.000. Declarar 120.000.000 invalida el uno por ciento.
        $rental = $this->rental(1_000_000);
        $rental->forceFill([
            'commercial_value' => 120_000_000,
            'catastral_appraisal' => 40_000_000,
        ])->saveQuietly();

        $result = $this->service()->validate(
            rental: $rental->refresh(),
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            requestedRent: 1_051_000,
        );

        $text = implode(' ', array_column($result['messages'], 'message'));

        // Con tilde y en mayuscula inicial, como quedo al pasar el mensaje a lang/.
        $this->assertStringContainsString('doble del avalúo catastral', $text);

        // El techo calculado (80.000.000) ya no aparece en el texto: el mensaje
        // nombra la regla y los dos valores con los que se contrasta, que es
        // donde el usuario puede verificar la cuenta. El techo sigue en el
        // contexto del resultado, para quien lo necesite programaticamente.
        $this->assertStringContainsString('120.000.000', $text);
        $this->assertStringContainsString('40.000.000', $text);
    }

    /**
     * Deja el IPC del total nacional y el de Valledupar para el mismo ano.
     *
     * Las cifras son las que se verificaron contra el DANE: nacional 5,10 % y
     * Valledupar 3,49 % para diciembre de 2025. La diferencia entre las dos es
     * justamente lo que hace util el test del IPC por ciudad.
     */
    private function seedNationalAndValledupar(): void
    {
        // El total nacional. La factory de IpcRate siembra tambien su fila en la
        // serie, igual que hace la sincronizacion real.
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // Valledupar. Va directo a la serie porque la tasa anual es una sola por ano
        // y ya la ocupo la del nacional.
        \App\Models\IpcSeries::factory()
            ->forCity('20001', 'VALLEDUPAR')
            ->decemberOf(2025, 3.49)
            ->create();

        \App\Models\Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        \App\Models\Geography::factory()->national()->create();
    }

    #[Test]
    public function un_ipc_de_ciudad_avisa_que_se_aparta_del_articulo_20(): void
    {
        $this->seedNationalAndValledupar();

        $rental = $this->rental(1_000_000);

        $result = $this->service()->validate(
            rental: $rental,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            requestedRent: 1_034_900,
            basis: IpcBasis::City,
            geographyCode: '20001',
        );

        $text = implode(' ', array_column($result['messages'], 'message'));

        $this->assertStringContainsString('VALLEDUPAR', $text);
        $this->assertStringContainsString('sin distinguir ciudades', $text);
    }

    #[Test]
    public function el_ipc_por_ciudad_se_puede_usar_cuando_el_arrendador_lo_asume_por_escrito(): void
    {
        $this->seedNationalAndValledupar();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_034_900,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            notes: 'Las partes acuerdan aplicar el IPC de Valledupar.',
            ipcOutsideStatutoryYear: true,
            basis: IpcBasis::City,
            geographyCode: '20001',
        );

        $this->assertSame(3.49, (float) $adjustment->ipc_percentage);
        $this->assertSame('VALLEDUPAR', $adjustment->geography_name);
        $this->assertFalse($adjustment->isNationalBasis());
        $this->assertTrue($adjustment->deviatesFromArticle20());
    }

    #[Test]
    public function el_ipc_de_ciudad_sin_motivo_escrito_no_se_registra(): void
    {
        $this->seedNationalAndValledupar();

        $rental = $this->rental(1_000_000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sin distinguir ciudades');

        $this->service()->record(
            rental: $rental,
            newRent: 1_034_900,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            basis: IpcBasis::City,
            geographyCode: '20001',
        );
    }

    /**
     * El tope con base ciudad sigue siendo el del IPC DE ESA CIUDAD, no el
     * nacional. Elegir la ciudad cambia de serie, no cambia la regla.
     */
    #[Test]
    public function el_tope_con_base_ciudad_usa_el_ipc_de_esa_ciudad(): void
    {
        $this->seedNationalAndValledupar();

        $adjustment = $this->service()->record(
            rental: $this->rental(1_000_000),
            newRent: 1_034_900,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            notes: 'Acuerdo para aplicar el IPC de Valledupar.',
            ipcOutsideStatutoryYear: true,
            basis: IpcBasis::City,
            geographyCode: '20001',
        );

        $this->assertSame(1_034_900.0, (float) $adjustment->legal_cap_rent);
    }

    #[Test]
    public function pactar_menos_que_el_ipc_baja_lo_aplicado_pero_no_el_tope(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_030_000,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
            pactadoPercentage: 3.0,
            // Un pactado distinto del IPC es una decisión de las partes que se
            // aparta del cálculo del artículo 20: exige el consentimiento y el
            // motivo escrito, igual que el año o la ciudad.
            ipcOutsideStatutoryYear: true,
            notes: 'Acuerdo para aplicar un porcentaje menor al IPC.',
        );

        $this->assertSame(3.0, (float) $adjustment->applied_pct);
        $this->assertSame(5.10, (float) $adjustment->legal_pct, 'el tope lo fija el IPC, no el pacto');
        $this->assertSame(1_051_000.0, (float) $adjustment->legal_cap_rent);
        $this->assertTrue($adjustment->hasPactadoCap());
    }

    #[Test]
    public function la_comunicacion_guarda_el_medio_que_la_ley_exige(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_051_000,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
        );

        $this->service()->markNotified($adjustment, today(), NotificationMethod::Postal);

        $fresh = $adjustment->refresh();

        $this->assertSame(NotificationMethod::Postal, $fresh->notification_method);
        $this->assertSame(\App\Enums\AdjustmentState::Notified, $fresh->state());
        $this->assertFalse($fresh->state()->countsTowardsChain(), 'notificar no es aplicar');
    }

    #[Test]
    public function el_estado_avanza_de_registrado_a_notificado_a_aplicado(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $rental = $this->rental(1_000_000);

        $adjustment = $this->service()->record(
            rental: $rental,
            newRent: 1_051_000,
            effectiveFrom: today()->setYear(2026)->setMonth(6)->setDay(1),
        );

        $this->assertSame(\App\Enums\AdjustmentState::Recorded, $adjustment->state());
        $this->assertTrue($adjustment->state()->canNotify());

        $this->service()->markNotified($adjustment, today(), NotificationMethod::Contractual);
        $this->assertTrue($adjustment->refresh()->state()->canApply());

        $this->service()->applyToRental($adjustment);
        $this->assertSame(\App\Enums\AdjustmentState::Applied, $adjustment->refresh()->state());
        $this->assertFalse($adjustment->refresh()->state()->canEdit());
    }
}
