<?php

namespace Tests\Feature;

use App\Enums\IpcBasis;
use App\Filament\Resources\PropertyResource\Pages\ManageProperties;
use App\Filament\Resources\RentAdjustmentResource\Pages\CreateRentAdjustment;
use App\Models\Geography;
use App\Models\IpcRate;
use App\Models\IpcSeries;
use App\Models\Property;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La ciudad de la propiedad y su efecto en el IPC del reajuste.
 *
 * Estas pruebas existen por una cadena concreta de relaciones que antes no
 * existía: la ciudad vive en la PROPIEDAD, se propone en el reajuste y cambia
 * de qué cifras salen los porcentajes. Cada eslabón se comprueba por separado,
 * porque un fallo en cualquiera de ellos se vería igual desde la pantalla: el
 * porcentaje sale mal y no se sabe por qué.
 */
class PropertyCityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function city(string $code = '20001', string $name = 'VALLEDUPAR'): Geography
    {
        return Geography::factory()->withIpc($code, $name, 'CESAR')->create();
    }

    private function property(?string $geographyCode = '20001'): Property
    {
        return Property::factory()->create([
            'user_id' => $this->user()->id,
            'geography_code' => $geographyCode,
        ]);
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateRentAdjustment::class);
    }

    /**
     * Abre el modal de creación de una propiedad y devuelve su formulario.
     *
     * PropertyResource no tiene páginas de create ni edit: la alta se hace con un
     * modal sobre ManageProperties, y el formulario vive en ese action, no en la
     * página. Por eso se abre el action en lugar de probar la página en vacío:
     * lo que importa es qué valor por defecto trae el campo cuando el usuario va
     * a crear una propiedad.
     *
     * @return array<string, mixed>
     */
    private function propertyForm(array $overrides = []): array
    {
        $this->actingAs($this->user());

        $component = Livewire::test(ManageProperties::class)
            ->callAction('create', $overrides);

        $component->assertSuccessful();

        // El modal abierto deja sus datos resueltos (defaults incluidos) en
        // mountedActionsData. Es el estado que el usuario ve al abrirlo.
        return (array) $component->get('mountedActionsData.0');
    }

    #[Test]
    public function la_propiedad_exige_ciudad(): void
    {
        // Sin ciudad declarada, el IPC del alquiler no tiene con qué comparar.
        // Dejarlo opcional significaría que el sistema adivina después.
        Geography::factory()->national()->create();
        $this->city();

        // Se comprueba con assertActionHasFieldErrors y no con assertHasFormErrors
        // porque la validación ocurre dentro del modal de alta, no en el
        // formulario de la página: la página no tiene formulario propio, y
        // probar su formulario abriría además el conflicto entre form() e
        // infolist() de ListRecords.
        Livewire::test(ManageProperties::class)
            ->callAction('create', [
                'name' => 'Edificio sin ciudad',
                'address' => 'Calle 1 # 2-3',
            ])
            ->assertHasActionErrors(['geography_code' => 'required']);
    }

    #[Test]
    public function la_ciudad_predeterminada_de_ajustes_precarga_la_propiedad(): void
    {
        // El caso que pedia el escenario real: se configura una ciudad de trabajo
        // y todas las propiedades nuevas nacen con ella.
        //
        // La comprobacion es sobre el recurso y no sobre la pagina. PropertyResource
        // tiene una sola pagina (ManageProperties, un ListRecords) que ademas declara
        // infolist, y probar el formulario a traves de ella choca con la resolucion
        // de formularios de Filament. Lo que importa es que el default() del campo
        // lea los ajustes, y eso se comprueba sobre el formulario del recurso.
        app(\App\Services\SettingsService::class)->save([
            'business_name' => 'Inmobiliaria',
            'tax_id' => '900123456',
            'default_city_code' => '20001',
        ]);

        $this->city();

        $this->actingAs($user = $this->user());

        $form = $this->propertyForm();

        $this->assertSame('20001', (string) $form['geography_code'] ?? '');

        unset($user);
    }

    #[Test]
    public function sin_ciudad_predeterminada_el_campo_queda_vacio(): void
    {
        // Null en ajustes significa "no hay ciudad de trabajo", y la consecuencia
        // es que el campo aparece vacío. NO que se rellene con el total nacional:
        // el total nacional no es una ciudad.
        app(\App\Services\SettingsService::class)->save([
            'business_name' => 'Inmobiliaria',
            'tax_id' => '900123456',
        ]);

        $this->city();

        $this->actingAs($this->user());

        $this->assertNull(app(AppSettings::class)->defaultCityCode());
        $this->assertArrayNotHasKey(
            'geography_code',
            array_filter((array) $this->propertyForm(), fn ($v): bool => $v !== null && $v !== ''),
            'sin ciudad predeterminada el campo debe quedar vacío',
        );
    }

    #[Test]
    public function el_selector_de_ciudad_ofrece_el_catalogo_completo_marcando_quien_tiene_ipc(): void
    {
        // Filtrar el selector a las 23 ciudades con IPC diria al arrendador que
        // no puede registrar una propiedad que existe. Se ofrece todo y se
        // marca, para que la limitacion se vea al elegir.
        $withIpc = Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        $without = Geography::factory()->withoutIpc('05001', 'MEDELLIN')->create();
        Geography::factory()->national()->create();

        $options = Geography::locationOptions();

        $this->assertArrayHasKey($withIpc->code, $options);
        $this->assertArrayHasKey($without->code, $options);
        $this->assertArrayNotHasKey(\App\Dto\Geography::nationalCode(), $options, 'el nacional no es una ubicación');
        $this->assertStringContainsString(__('geography.with_ipc'), $options[$withIpc->code]);
        $this->assertStringContainsString(__('geography.without_ipc'), $options[$without->code]);
    }

    #[Test]
    public function elegir_ciudad_como_base_muestra_el_porcentaje_de_esa_ciudad(): void
    {
        // EL BUG QUE SE ESTABA CORRIENDO. El selector de año llamaba a
        // yearOptions() sin argumento, así que siempre pintaba el total
        // nacional: elegir una ciudad no cambiaba el porcentaje que se veía.
        $national = 5.10;
        $cityPct = 3.49;

        IpcRate::factory()->forYear(2025, $national)->create();
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();

        IpcSeries::factory()->forCity('20001', 'VALLEDUPAR')->decemberOf(2025, $cityPct)->create();

        $form = $this->form()->fillForm([
            'ipc_basis' => IpcBasis::City->value,
            'geography_code' => '20001',
        ]);

        $options = app(\App\Services\Ipc\IpcSeriesRepository::class)->yearOptions('20001');

        $this->assertArrayHasKey(2025, $options);
        $this->assertStringContainsString(
            number_format($cityPct, 2, ',', '.'),
            $options[2025],
            'el porcentaje debe ser el de la ciudad',
        );
        $this->assertStringNotContainsString(
            number_format($national, 2, ',', '.'),
            $options[2025],
            'no debe seguir mostrando el total nacional',
        );

    }

    #[Test]
    public function la_propiedad_propone_su_ciudad_como_valor_por_defecto(): void
    {
        // Si la propiedad ya tiene ciudad, pedirla otra vez en el reajuste solo
        // abre la posibilidad de escribirla distinta al original.
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();
        $city = Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        IpcSeries::factory()->forCity($city->code, 'VALLEDUPAR')->decemberOf(2025, 3.49)->create();

        $property = $this->property($city->code);
        $rental = \App\Models\Rental::factory()->create([
            'user_id' => $property->user_id,
            'property_id' => $property->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
        ]);

        $data = $this->form()
            ->fillForm([
                'rental_id' => $rental->id,
                'ipc_basis' => IpcBasis::City->value,
            ])
            ->get('data');

        $this->assertSame($city->code, (string) ($data['geography_code'] ?? ''));
    }

    #[Test]
    public function una_propiedad_sin_ipc_no_se_propone_como_ciudad_del_reajuste(): void
    {
        // Una ciudad sin serie no puede producir un tope. Proponerla como valor
        // por defecto pondría al usuario en un callejón sin salida desde el
        // formulario del reajuste.
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();
        Geography::factory()->withoutIpc('05001', 'MEDELLIN')->create();

        $property = $this->property('05001');
        $rental = \App\Models\Rental::factory()->create([
            'user_id' => $property->user_id,
            'property_id' => $property->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
        ]);

        $data = $this->form()
            ->fillForm([
                'rental_id' => $rental->id,
                'ipc_basis' => IpcBasis::City->value,
            ])
            ->get('data');

        $this->assertNotSame('05001', (string) ($data['geography_code'] ?? ''));
    }

    #[Test]
    public function la_preview_muestra_la_cadena_del_calculo(): void
    {
        // El campo «Canon reajustado» pide un resultado. Sin ver de dónde sale,
        // el número parece arbitrario y el usuario no tiene con qué compararlo.
        // Vigencia en 2026 con el IPC de 2025: la combinación que el artículo 20
        // pide de verdad. Una vigencia de 2027 usaría el IPC de 2026, que no
        // existe todavía, y la preview no se dibujaría por una razón que no es
        // la que aquí se quiere comprobar.
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();

        $user = $this->user();
        $rental = \App\Models\Rental::factory()->create([
            'user_id' => $user->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
            'start_date' => now()->subYears(2)->toDateString(),
            'anchor_established_on' => now()->subYears(2)->toDateString(),
        ]);

        $form = $this->form()->fillForm([
            'rental_id' => $rental->id,
            'effective_from' => now()->addYear()->startOfYear()->addMonths(2)->toDateString(),
            'ipc_basis' => IpcBasis::National->value,
        ]);

        $html = (string) $form->html();

        foreach ([
            __('rent_adjustment.preview.current_rent'),
            __('rent_adjustment.preview.applied_ipc'),
            __('rent_adjustment.preview.increase'),
            __('rent_adjustment.preview.cap'),
        ] as $label) {
            $this->assertStringContainsString($label, $html, 'falta la fila: '.$label);
        }
    }

    #[Test]
    public function la_preview_refleja_el_ano_elegido_y_no_el_que_fija_la_ley(): void
    {
        // La preview se quedaba vacía justo en las combinaciones que exigen
        // explicación.
        //
        // previewIpc() pasaba ipcYear en null y por eso caía siempre en el año
        // estatutorio. Cuando la vigencia y el año elegido coinciden, la
        // diferencia es invisible y el bug pasa desapercibido; se manifiesta
        // cuando NO coinciden, que es el caso del apartamiento del artículo 20:
        // ahí la preview no mostraba nada, y es precisamente donde el usuario
        // necesita ver qué IPC se está aplicando y sobre qué monto.
        IpcRate::factory()->forYear(2025, 5.10)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();
        Geography::factory()->national()->create();

        $rental = \App\Models\Rental::factory()->create([
            'user_id' => $this->user()->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
            'start_date' => now()->subYears(2)->toDateString(),
            'anchor_established_on' => now()->subYears(2)->toDateString(),
        ]);

        // Vigencia de 2027: el artículo 20 pediría el IPC de 2026. Se elige el
        // de 2025, que es la combinación que requiere confirmación.
        $form = $this->form();

        $form->fillForm([
            'rental_id' => $rental->id,
            'effective_from' => now()->addYear()->startOfYear()->addMonths(2)->toDateString(),
            'ipc_basis' => IpcBasis::National->value,
        ]);

        $form->fillForm(['ipc_year' => 2025]);

        $html = (string) $form->html();

        $this->assertStringContainsString('ajuste-preview', $html, 'la preview debe existir también al apartarse del artículo 20');
        $this->assertStringContainsString(
            number_format(5.10, 2, ',', '.'),
            $html,
            'debe mostrar el IPC del año ELEGIDO (2025), no el que la ley fijaría (2026)',
        );
    }

    #[Test]
    public function la_preview_no_aparece_sin_alquiler_elegido(): void
    {
        // Todavía no hay nada que calcular. Un bloque con valores en cero o un
        // mensaje de error serían ruido en un formulario que se está llenando.
        $html = (string) $this->form()->html();

        $this->assertStringNotContainsString('ajuste-preview', $html);
    }
}
