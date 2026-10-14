<?php

namespace Tests\Feature;

use App\Filament\Resources\RentAdjustmentResource\Pages\CreateRentAdjustment;
use App\Filament\Resources\RentAdjustmentResource\Pages\ListRentAdjustments;
use App\Models\IpcRate;
use App\Models\Rental;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * El formulario de registro de reajustes.
 *
 * ESTA ARCHIVO CAMBIO DE PREMISA CON LA REVISION DEL MODULO.
 *
 * Antes fijaba que la vigencia propuesta fuera el 1 de enero del ano siguiente al
 * del IPC. Esa era una lectura equivocada del articulo 20 y una de las razones por
 * las que el formulario obligaba al usuario a corregir a mano la fecha que el
 * sistema le ponia.
 *
 * El articulo 20 dice "cada doce (12) meses de ejecucion del contrato BAJO UN
 * MISMO PRECIO". El plazo corre desde el ultimo cambio de canon, no desde el
 * arranque del ano. Por eso la vigencia propuesta es el aniversario, y el ano del
 * IPC se deriva de ESA fecha.
 *
 * Lo que este archivo fija ahora:
 *
 *  - la vigencia propuesta no es el 1 de enero;
 *  - cambia cuando se elige el alquiler, porque el aniversario depende de el;
 *  - la combinacion por defecto ya cumple el articulo 20;
 *  - apartarse exige confirmacion Y motivo escrito.
 */
class RentAdjustmentFormTest extends DocumentTestCase
{
    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateRentAdjustment::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (array) $this->form()->get('data');
    }

    /**
     * Un alquiler con contrato de mas de un ano.
     *
     * El inicio se fija porque el articulo 20 exige doce meses bajo un mismo
     * precio. Sin esto, un alquiler recien creado todavia no podria reajustarse y
     * las pruebas fallarian por el plazo y no por lo que quieren comprobar.
     */
    private function rental(float $monthlyAmount = 1_000_000, ?Carbon $start = null): Rental
    {
        $start ??= today()->subYears(2);

        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => $monthlyAmount,
            'anchor_monthly_amount' => $monthlyAmount,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->toDateString(),
            'anchor_established_on' => $start->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * El ano del IPC sembrado mas reciente.
     */
    private function lastIpcYear(): int
    {
        $years = app(\App\Services\Ipc\IpcSeriesRepository::class)->availableYears();

        return $years[0] ?? (int) today()->subYear()->format('Y');
    }

    /**
     * Una vigencia legal para el IPC sembrado: el ano siguiente a ese IPC.
     */
    private function legalEffectiveFrom(int $ipcYear): string
    {
        $date = Carbon::create($ipcYear + 1, 1, 1);

        return ($date->isFuture() ? $date : today())->toDateString();
    }

    /**
     * Rellena el formulario de modo que se aparte del articulo 20.
     *
     * El ano del IPC se escribe EN UNA SEGUNDA llamada, despues de la vigencia.
     * El orden no es cosmetico: la fecha DELEGA el ano, porque el articulo 20
     * toma el ano calendario anterior al de vigencia. Si ambos se escribieran en
     * el mismo fillForm, la fecha podria aplicarse despues y pisar el ano, la
     * combinacion dejaria de apartarse de la ley y la prueba mediria otra cosa.
     *
     * @param  array<string, mixed>  $campos
     */
    private function apartandoseDelArticulo20(int $ipcYear, array $campos = []): \Livewire\Features\SupportTesting\Testable
    {
        $component = $this->form()->fillForm($campos);

        $component->fillForm(['ipc_year' => (string) $ipcYear]);

        return $component;
    }

    /**
     * Una fecha de vigencia dentro del ano pedido que todavia no paso.
     *
     * Existe porque "vigencia en 2026" por si solo no alcanza: si la fecha cae
     * en un dia ya transcurrido, el formulario la rechaza por su propia
     * validacion y la prueba mide lo que no debe.
     */
    private function futureDateIn(int $year): string
    {
        $date = Carbon::create($year, 12, 15);

        return ($date->isFuture() ? $date : Carbon::create($year, 12, 31))->toDateString();
    }

    #[Test]
    public function el_ano_del_ipc_sale_de_lo_sincronizado(): void
    {
        // El selector no tiene una lista escrita en el codigo: ofrece lo que hay.
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $defaults = $this->defaults();

        $this->assertSame('2025', (string) ($defaults['ipc_year'] ?? ''));
    }

    #[Test]
    public function elegir_el_alquiler_propone_su_aniversario_y_no_el_primero_de_enero(): void
    {
        // El cambio de fondo: la vigencia sale del articulo 20, no del calendario.
        // Un alquiler que empezó el 15 de noviembre propone el 15 de noviembre.
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $start = Carbon::create(2025, 11, 15);

        $data = $this->form()
            ->fillForm(['rental_id' => $this->rental(1_000_000, $start)->id])
            ->get('data');

        $effective = Carbon::parse((string) $data['effective_from']);

        $this->assertSame(
            11,
            $effective->month,
            'la vigencia debe caer en el mes en que empezo el contrato, no en enero',
        );
        // El 15 de noviembre de 2026 es DOMINGO. El plazo legal no se mueve, pero
        // la vigencia propuesta avanza al lunes 16 para que el aviso por correo
        // autorizado del parrafo 2 pueda entregarse ese dia.
        $this->assertSame(16, $effective->day, 'el domingo 15 se propone el lunes siguiente');
        $this->assertTrue($effective->isWeekday(), 'la vigencia propuesta debe ser un dia habil');
    }

    #[Test]
    public function un_aniversario_que_ya_es_dia_habil_se_propongue_tal_cual(): void
    {
        // El caso normal: si el aniversario cae en dia habil no se mueve nada.
        // Moverlo siempre seria inventarse un cambio que la ley no pide.
        // El 20 de noviembre de 2026 es VIERNES y esta en el futuro: el
        // aniversario no vence y hay que respetarlo tal cual.
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $start = Carbon::create(2025, 11, 20);

        $data = $this->form()
            ->fillForm(['rental_id' => $this->rental(1_000_000, $start)->id])
            ->get('data');

        $this->assertSame(
            Carbon::create(2026, 11, 20)->toDateString(),
            (string) $data['effective_from'],
        );
    }

    #[Test]
    public function cambiar_la_vigencia_recalcula_el_ano_del_ipc(): void
    {
        // El ano del IPC DELEGA en la fecha: es el ano calendario anterior, que es
        // lo que manda el articulo 20. Dejarlo suelto hacia que el formulario
        // naciera con una pareja incoherente que el usuario tenia que corregir.
        IpcRate::factory()->forYear(2023, 9.28)->create();
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // No se elige alquiler a proposito: elegirlo tambien fija la vigencia, y
        // aqui lo que se prueba es que la FECHA manda sobre el ano. Sin
        // alquiler, la unica fuente del ano es la vigencia.
        $component = $this->form()->fillForm(['effective_from' => '2026-12-15']);

        $this->assertSame(
            '2025',
            (string) $component->get('data')['ipc_year'],
            'una vigencia de 2026 usa el IPC de 2025',
        );
    }

    #[Test]
    public function una_vigencia_del_ano_que_aun_no_se_cierra_cae_en_el_ultimo_ipc_disponible(): void
    {
        // En 2027 el articulo 20 pediria el IPC de 2026, que todavia no esta
        // cerrado a dia de hoy. El formulario no puede inventar ese dato: ofrece
        // el ultimo cerrado y deja que el panel de comprobaciones avise del
        // desvio, que es lo unico que se puede hacer sin inventar.
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $component = $this->form()->fillForm(['effective_from' => '2027-03-15']);

        $this->assertSame(
            '2025',
            (string) $component->get('data')['ipc_year'],
            'el ultimo IPC cerrado es la unica cifra defendible',
        );
    }

    #[Test]
    public function un_aniversario_ya_vencido_propone_hoy_y_no_una_fecha_pasada(): void
    {
        // El aniversario de este alquiler cayo el ano pasado. Proponer esa fecha
        // seria proponer un reajuste en el pasado, que el propio formulario
        // rechaza y que descuadra las facturas.
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $start = Carbon::create(2023, 6, 1);

        $data = $this->form()
            ->fillForm(['rental_id' => $this->rental(1_000_000, $start)->id])
            ->get('data');

        $this->assertSame(
            today()->toDateString(),
            (string) $data['effective_from'],
        );
    }

    #[Test]
    public function la_vigencia_propuesta_nunca_es_pasada(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $effective = (string) ($this->defaults()['effective_from'] ?? '');

        $this->assertGreaterThanOrEqual(
            today()->toDateString(),
            $effective,
            'no se puede proponer un reajuste en el pasado',
        );
    }

    #[Test]
    public function el_canon_propuesto_es_el_del_ipc_del_ano_anterior(): void
    {
        // El formulario sugiere el tope, que es lo que el DANE respalda.
        // Un IPC de 5,10 % sobre 1.000.000 son 1.051.000.
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        $start = Carbon::create(2024, 3, 15);

        $data = $this->form()
            ->fillForm([
                'rental_id' => $this->rental(1_000_000, $start)->id,
                'effective_from' => '2026-03-15',
            ])
            ->get('data');

        $this->assertSame(
            '1051000',
            number_format((float) ($data['new_rent'] ?? 0), 0, '.', ''),
        );
    }

    #[Test]
    public function la_confirmacion_no_aparece_con_la_combinacion_legal(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // Con el año correcto para la vigencia no hay nada que confirmar, y la
        // SECCIÓN ENTERA no existe. Antes la sección estaba siempre presente con la
        // casilla oculta dentro, lo que ocupaba espacio y sugería que había algo
        // pendiente aunque no lo hubiera.
        $html = $this->form()
            ->fillForm([
                'ipc_year' => '2025',
                'effective_from' => '2026-06-01',
            ])
            ->html();

        $this->assertStringNotContainsString(
            'ipc_outside_statutory_year',
            $html,
            'con la combinación legal no debe existir nada que confirmar',
        );
        $this->assertStringNotContainsString(
            __('rent_adjustment.sections.party_decisions_reasons'),
            $html,
            'tampoco debe aparecer la lista de motivos',
        );
    }

    #[Test]
    public function la_confirmacion_aparece_cuando_el_ano_se_aparta_de_la_ley(): void
    {
        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();

        // Vigencia en 2026 con el IPC de 2026 se aparta del articulo 20.
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $this->apartandoseDelArticulo20(2026, ['effective_from' => '2026-06-01'])
            ->assertFormFieldExists('ipc_outside_statutory_year', fn ($field): bool => $field->isVisible());
    }

    #[Test]
    public function elegir_la_ciudad_tambien_pide_confirmacion(): void
    {
        // El articulo 20 habla de "el indice de precios al consumidor" sin
        // distinguir ciudades. Usar el IPC de una ciudad es apartarse del texto,
        // aunque el ano sea el correcto.
        IpcRate::factory()->forYear(2025, 5.10)->create();
        \App\Models\Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        \App\Models\Geography::factory()->national()->create();

        $this->form()
            ->fillForm([
                'rental_id' => $this->rental()->id,
                'ipc_basis' => \App\Enums\IpcBasis::City->value,
                'geography_code' => '20001',
                'effective_from' => '2026-06-01',
            ])
            ->assertFormFieldExists('ipc_outside_statutory_year', fn ($field): bool => $field->isVisible());
    }

    #[Test]
    public function registrar_un_reajuste_dentro_de_la_ley_no_pide_confirmacion(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        $rental = $this->rental(1_000_000);

        Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $rental->id,
                'new_rent' => 1_051_000,
                'ipc_year' => '2025',
                'ipc_basis' => \App\Enums\IpcBasis::National->value,
                'effective_from' => $this->legalEffectiveFrom(2025),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rent_adjustments', [
            'rental_id' => $rental->id,
            'ipc_year' => 2025,
            'ipc_percentage' => 5.10,
        ]);
    }

    #[Test]
    public function un_ipc_que_no_corresponde_no_se_guarda_en_silencio(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();
        $rental = $this->rental(1_000_000);

        // Vigencia en 2026 con el IPC de 2026 es la combinacion que la ley no
        // permite sin reconocimiento: el articulo 20 fija el IPC del ano
        // CALENDARIO ANTERIOR al de vigencia, que aqui seria 2025.
        $this->apartandoseDelArticulo20(2026, [
            'rental_id' => $rental->id,
            'new_rent' => 1_041_500,
            'effective_from' => $this->futureDateIn(2026),
        ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseMissing('rent_adjustments', ['rental_id' => $rental->id]);
    }

    #[Test]
    public function apartarse_del_ano_legal_con_motivo_escrito_si_se_registra(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();
        $rental = $this->rental(1_000_000);

        $this->apartandoseDelArticulo20(2026, [
            'rental_id' => $rental->id,
            'new_rent' => 1_041_500,
            'effective_from' => $this->futureDateIn(2026),
            'ipc_outside_statutory_year' => true,
            'notes' => 'El IPC de 2025 fue revisado por el DANE.',
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rent_adjustments', [
            'rental_id' => $rental->id,
            'ipc_year' => 2026,
        ]);
    }

    #[Test]
    public function el_listado_de_reajustes_carga(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();

        Livewire::test(ListRentAdjustments::class)->assertSuccessful();
    }
}
