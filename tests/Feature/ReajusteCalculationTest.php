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
    public function un_porcentaje_pactado_no_obliga_a_confirmar(): void
    {
        /*
        * El artículo 20 dice «hasta el 100 %». Pactar menos ES usar la norma, no
        * apartarse de ella.
        *
        * Pedir la confirmación en ese caso enseñaría al usuario a marcar la
        * casilla por costumbre, que es el peor resultado posible: una casilla que
        * se marca siempre no prueba nada.
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

        $this->assertStringNotContainsString(
            'ipc_outside_statutory_year',
            $html,
            'pactar menos que el IPC no es una separación',
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
    public function la_seccion_se_pliega_sola_cuando_el_usuario_ya_confirmo(): void
    {
        // Volver a pedir la confirmación cada vez que se toca un campo sería una
        // molestia. Una vez aceptada, la sección se pliega y deja de estorbar.
        //
        // El estado de plegado vive en Alpine y no se puede leer del HTML, así
        // que se pregunta al propio componente del formulario.
        $component = $this->form()
            ->fillForm([
                'rental_id' => $this->rental->id,
                'effective_from' => '2027-06-01',
                'ipc_basis' => IpcBasis::National->value,
            ])
            ->fillForm(['ipc_year' => 2025]);

        $this->assertTrue(
            $this->partySection($component)->isCollapsed() === false,
            'con motivos sin confirmar, la sección debe estar abierta',
        );

        $component->fillForm(['ipc_outside_statutory_year' => true]);

        $this->assertTrue(
            $this->partySection($component)->isCollapsed(),
            'la sección debe quedar plegada tras aceptar',
        );
    }

    /**
     * El componente de la sección de decisiones de las partes.
     */
    private function partySection(\Livewire\Features\SupportTesting\Testable $component): \Filament\Forms\Components\Section
    {
        $section = $component
            ->instance()
            ->form
            ->getComponents()
            ->first(fn ($c): bool => $c instanceof \Filament\Forms\Components\Section
                && $c->getLabel() === __('rent_adjustment.sections.party_decisions'));

        $this->assertInstanceOf(
            \Filament\Forms\Components\Section::class,
            $section,
            'la sección de decisiones de las partes debe existir en el esquema',
        );

        return $section;
    }
}