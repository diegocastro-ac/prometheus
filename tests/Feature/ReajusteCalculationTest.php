<?php

namespace Tests\Feature;

use App\Enums\IpcBasis;
use App\Filament\Resources\RentAdjustmentResource\Pages\CreateRentAdjustment;
use App\Models\Geography;
use App\Models\IpcRate;
use App\Models\IpcSeries;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La tabla del cálculo y el comportamiento dinámico del formulario.
 *
 * Dos cosas se comprueban aquí, y las dos son la diferencia entre un formulario
 * que hay que leer y uno que hay que entender:
 *
 * 1. La tabla enseña la CUENTA, no solo el resultado. Un canon reajustado sin
 *    ver de dónde sale es un número que el usuario no tiene por qué aceptar.
 *
 * 2. Lo que las partes deben decidir depende de las SELECCIONES, no de un estado
 *    guardado. La sección aparece con el motivo y desaparece cuando ya no hay
 *    nada, y eso no se puede comprobar mirando el esquema: hay que mirar el
 *    resultado de varias combinaciones.
 */
class ReajusteCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        IpcRate::factory()->forYear(2024, 5.20)->create();
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        IpcSeries::factory()->forCity('20001', 'VALLEDUPAR')->decemberOf(2025, 3.49)->create();

        $this->rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
            'start_date' => now()->subYears(2)->toDateString(),
            'anchor_established_on' => now()->subYears(2)->toDateString(),
        ]);
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateRentAdjustment::class);
    }

    private function html(array $overrides = []): string
    {
        return $this->form()
            ->fillForm(array_merge([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
            ], $overrides))
            ->html();
    }

    #[Test]
    public function la_tabla_muestra_la_cadena_del_calculo_completa(): void
    {
        $html = $this->html(['ipc_year' => '2025']);

        // El canon vigente sobre el que se aplica, el porcentaje con su año y su
        // geografía, el incremento y el tope.
        $this->assertStringContainsString(__('rent_adjustment.preview.current_rent'), $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.applied_ipc'), $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.increase'), $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.cap'), $html);

        // 1.000.000 con un IPC del 5,10 % da 51.000 de incremento y 1.051.000 de
        // tope. Las tres cifras tienen que estar, en ese orden.
        $this->assertStringContainsString('1.000.000', $html);
        $this->assertStringContainsString('51.000', $html);
        $this->assertStringContainsString('1.051.000', $html);
    }

    #[Test]
    public function la_tabla_dice_de_que_ano_y_de_que_geografia_es_el_ipc(): void
    {
        $html = $this->html(['ipc_year' => '2025']);

        // Sin esto el 5,10 % es un número sin contexto, y no se puede contrastar
        // contra la fuente.
        $this->assertStringContainsString(
            __('rent_adjustment.preview.applied_ipc_value', [
                'year' => 2025,
                'geography' => 'NACIONAL',
            ]),
            $html,
        );
    }

    #[Test]
    public function el_tope_y_el_canon_que_se_registra_son_totales_y_no_lineas(): void
    {
        $html = $this->html(['ipc_year' => '2025', 'new_rent' => 1_051_000]);

        // El canon que se registrará solo aparece cuando el usuario ya escribió
        // algo. Antes de eso es el mismo tope, y mostrarlo dos veces como dos
        // filas distintas haría pensar que son dos cifras.
        $this->assertStringContainsString('ajuste-preview__total', $html);
        $this->assertStringContainsString('ajuste-preview__total--chosen', $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.selected_rent'), $html);
    }

    #[Test]
    public function con_el_tope_exacto_no_se_muestra_una_diferencia_de_cero(): void
    {
        $html = $this->html(['ipc_year' => '2025', 'new_rent' => 1_051_000]);

        $this->assertStringNotContainsString(
            __('rent_adjustment.preview.above_cap'),
            $html,
            'coincidir con el tope no es una diferencia',
        );
        $this->assertStringNotContainsString(
            __('rent_adjustment.preview.below_cap'),
            $html,
        );
    }

    #[Test]
    public function un_canon_por_encima_del_tope_muestra_el_exceso(): void
    {
        $html = $this->html(['ipc_year' => '2025', 'new_rent' => 1_200_000]);

        $this->assertStringContainsString(__('rent_adjustment.preview.above_cap'), $html);
        // 1.200.000 − 1.051.000 = 149.000
        $this->assertStringContainsString('149.000', $html);
        $this->assertStringContainsString('ajuste-preview__row--excess', $html);
    }

    #[Test]
    public function la_seccion_de_partes_no_existe_cuando_todo_es_legal(): void
    {
        $html = $this->html(['ipc_year' => '2025']);

        $this->assertStringNotContainsString('ipc_outside_statutory_year', $html);
        $this->assertStringNotContainsString('ajuste-reasons', $html);
    }

    #[Test]
    public function la_seccion_de_partes_aparece_con_el_motivo_cuando_se_aparta(): void
    {
        // Vigencia en 2026 con el IPC de 2025: el artículo 20 pide el de 2025,
        // así que este caso SÍ es legal. Para apartarse hace falta lo contrario.
        IpcRate::factory()->forYear(2026, 4.15)->create();

        $html = $this->html(['ipc_year' => '2025', 'effective_from' => '2027-06-01']);

        $this->assertStringContainsString('ipc_outside_statutory_year', $html);
        $this->assertStringContainsString('ajuste-reasons', $html);
        $this->assertStringContainsString(__('rent_adjustment.sections.party_decisions_reasons'), $html);
        $this->assertStringContainsString(
            __('rent_adjustment.deviation.year', ['chosen' => 2025, 'statutory' => 2026]),
            $html,
            'el motivo tiene que decir qué año se aplicó y cuál corresponde',
        );
    }

    #[Test]
    public function elegir_base_ciudad_es_un_motivo_y_se_explica(): void
    {
        $html = $this->html([
            'ipc_year' => '2025',
            'ipc_basis' => IpcBasis::City->value,
            'geography_code' => '20001',
        ]);

        $this->assertStringContainsString('ipc_outside_statutory_year', $html);
        $this->assertStringContainsString(
            __('rent_adjustment.deviation.city', ['geography' => 'VALLEDUPAR']),
            $html,
        );
    }

    #[Test]
    public function un_porcentaje_pactado_distinto_del_ipc_obliga_a_confirmar(): void
    {
        /*
        * El «Porcentaje pactado» se autocompleta con el % del año del IPC.
        * Si el usuario lo cambia por una cifra distinta, eso es una decisión de
        * las partes que se aparta del cálculo del artículo 20 y debe quedar
        * confirmada y explicada, igual que el año o la ciudad.
        *
        * El 2025 nacional es 5,10 %, así que pactar 2,5 % es apartarse.
        */
        $html = $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
                'ipc_year' => '2025',
                'pactado_pct' => 2.5,
            ])
            ->html();

        $this->assertStringContainsString(
            'ipc_outside_statutory_year',
            $html,
            'un pactado distinto del IPC del año es una separación que confirmar',
        );
        $this->assertStringContainsString(
            __('rent_adjustment.deviation.pactado', ['pct' => '2,50']),
            $html,
        );
    }

    #[Test]
    public function un_porcentaje_pactado_igual_al_ipc_no_obliga_a_confirmar(): void
    {
        /*
        * El caso corriente: el pactado coincide con el % del año del IPC, que es
        * justo el valor con el que se autocompleta el campo. No hay separación
        * que confirmar, y la sección entera no debe existir.
        */
        $html = $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
                'ipc_year' => '2025',
                'pactado_pct' => 5.10,
            ])
            ->html();

        $this->assertStringNotContainsString(
            'ipc_outside_statutory_year',
            $html,
            'un pactado igual al IPC del año no es una separación',
        );
    }

    #[Test]
    public function un_exceso_si_es_un_motivo_que_requiere_confirmacion(): void
    {
        $html = $this->html(['ipc_year' => '2025', 'new_rent' => 1_500_000]);

        $this->assertStringContainsString('ipc_outside_statutory_year', $html);
        $this->assertStringContainsString(
            __('rent_adjustment.deviation.excess', ['amount' => 'COP 449.000']),
            $html,
        );
    }

    #[Test]
    public function la_seccion_no_se_pliega_al_confirmar_para_no_mover_el_formulario(): void
    {
        /*
         * Antes, al aceptar, la sección se plegaba sola. Eso provocaba un salto
         * de tamaño en el formulario cada vez que se tocaba el interruptor de
         * confirmación: el contenido desaparecía de golpe y las secciones de
         * abajo subían.
         *
         * Ahora la sección se queda abierta. Este test lo comprueba por el
         * resultado observable: tras marcar la confirmación, los campos de la
         * sección (el porcentaje pactado y las observaciones) siguen presentes
         * en el HTML, en lugar de desaparecer con el plegado.
         */
        $html = $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2027-06-01',
                'ipc_basis' => IpcBasis::National->value,
                'ipc_year' => 2025,
                'ipc_outside_statutory_year' => true,
            ])
            ->html();

        // La sección sigue desplegada: sus campos se siguen renderizando.
        $this->assertStringContainsString(
            __('rent_adjustment.form.pactado_pct'),
            $html,
            'la sección de partes debe seguir abierta tras confirmar, sin plegarse',
        );
        $this->assertStringContainsString(
            __('rent_adjustment.form.notes'),
            $html,
            'las observaciones deben seguir visibles: la sección no se pliega',
        );
    }

    #[Test]
    public function mover_el_ano_actualiza_el_porcentaje_pactado(): void
    {
        /*
        * El «Porcentaje pactado» queda atado al año del IPC. Al mover el año, el
        * campo toma el % de ese año sin que el usuario tenga que escribirlo: el
        * 2025 es 5,10 % y el 2024 es 5,20 %.
        */
        $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
                'ipc_year' => '2025',
            ])
            ->assertFormSet(['pactado_pct' => 5.10])
            ->fillForm(['ipc_year' => '2024'])
            ->assertFormSet(['pactado_pct' => 5.20]);
    }

    #[Test]
    public function el_porcentaje_pactado_arranca_con_el_del_ano_al_cargar(): void
    {
        /*
        * Al cargar el formulario, sin tocar nada, el «Porcentaje pactado» ya
        * muestra el % del año del IPC por defecto (2025 = 5,10 %), no arranca
        * vacío esperando a que se cambie el año.
        */
        $data = $this->form()->get('data');

        $this->assertSame(5.10, (float) ($data['pactado_pct'] ?? 0));
    }

    #[Test]
    public function las_observaciones_son_obligatorias_cuando_hay_apartamiento(): void
    {
        /*
        * En cuanto la combinación se aparta del artículo 20, la sección «Lo que
        * decidan las partes» exige el motivo escrito. Sin él, el registro se
        * rechaza por validación del formulario y no se guarda.
        */
        Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
                // 2024 se aparta del año legal (2025) para esta vigencia.
                'ipc_year' => '2024',
                'new_rent' => 1_052_000,
            ])
            ->call('create')
            ->assertHasFormErrors(['notes' => 'required']);

        $this->assertDatabaseMissing('rent_adjustments', ['rental_id' => $this->rental->id]);
    }

    #[Test]
    public function las_observaciones_no_son_obligatorias_con_la_combinacion_legal(): void
    {
        /*
        * Con la combinación que cumple el artículo 20 no hay nada que confirmar:
        * la sección de partes no existe, así que las observaciones no se exigen
        * ni aparece su aviso de obligatoriedad.
        */
        $html = $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2026-06-01',
                'ipc_basis' => IpcBasis::National->value,
                'ipc_year' => '2025',
            ])
            ->html();

        $this->assertStringNotContainsString('ipc_outside_statutory_year', $html);
        $this->assertStringNotContainsString('notes_help_required', $html);
    }
}
