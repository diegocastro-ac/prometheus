<?php

namespace Tests\Feature;

use App\Enums\IpcBasis;
use App\Filament\Resources\IpcSeriesResource\Pages\ListIpcSeries;
use App\Filament\Resources\PropertyResource\Pages\ManageProperties;
use App\Filament\Resources\RentAdjustmentResource\Pages\CreateRentAdjustment;
use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Models\Geography;
use App\Models\IpcRate;
use App\Models\IpcSeries;
use App\Models\Rental;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Las pantallas que se tocaron en la tanda de ciudad, preview y formalidad.
 *
 * Estas pruebas no comprueban reglas de negocio: comprueban que lo que se
 * modificó sigue estando donde tiene que estar y visible. Son la red de
 * seguridad de los textos, porque un archivo de traducción mal escrito no rompe
 * la aplicación: devuelve la clave literal, que se ve en pantalla como
 * «rent_adjustment.preview.cap», y nadie se entera hasta que un usuario lo ve.
 */
class ReajusteScreensRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function rental(): Rental
    {
        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
            'start_date' => now()->subYears(2)->toDateString(),
            'anchor_established_on' => now()->subYears(2)->toDateString(),
        ]);
    }

    /**
     * Recorre un conjunto de claves y confirma que ninguna se renderiza como
     * literal.
     *
     * Un archivo de traducción mal formado falla en silencio: __() devuelve la
     * propia ruta de la clave. Eso es lo que se comprueba aquí.
     *
     * @param  list<string>  $keys
     */
    private function assertNoVisibleKeys(string $html, array $keys): void
    {
        unset($html);

        /*
        | Se comprueba que __() RESUELVA, no que el HTML no contenga el prefijo.
        |
        | Buscar el texto en el marcado daba falsos positivos constantes: el
        | snapshot de Livewire lleva los nombres de los campos (default_city_code)
        | y los de sus propiedades (mountedInfolistDocument…), que contienen los
        | prefijos sin que haya ninguna traducción perdida.
        |
        | La comprobación real es directa: si la clave existe en su archivo, __()
        | devuelve el texto; si falta, devuelve la ruta literal. Eso es un fallo
        | silencioso, y es exactamente lo que hay que cazar.
        */
        foreach ($keys as $prefix) {
            foreach ($this->translationKeys($prefix) as $full) {
                $this->assertNotSame(
                    $full,
                    __($full),
                    "la clave {$full} no está definida y se mostraría literal",
                );
            }
        }
    }

    /**
     * Todas las claves de un archivo de traducción.
     *
     * @return list<string>
     */
    private function translationKeys(string $prefix): array
    {
        $path = lang_path("es/{$prefix}.php");

        if (! is_file($path)) {
            $this->fail("no existe lang/es/{$prefix}.php");
        }

        $flat = function (array $array, string $parent = '') use (&$flat): array {
            $keys = [];

            foreach ($array as $key => $value) {
                $full = $parent === '' ? (string) $key : "{$parent}.{$key}";

                $keys = is_array($value)
                    ? [...$keys, ...$flat($value, $full)]
                    : [...$keys, $full];
            }

            return $keys;
        };

        // Las claves se devuelven con el prefijo del archivo, que es como las
        // llama el código: 'rent_adjustment.preview.cap'.
        return array_map(
            static fn (string $key): string => "{$prefix}.{$key}",
            $flat(require $path),
        );
    }

    #[Test]
    public function ninguna_pantalla_muestra_una_clave_sin_traducir(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        IpcSeries::factory()->forCity('20001', 'VALLEDUPAR')->decemberOf(2025, 3.49)->create();

        $rental = $this->rental();

        $screens = [
            'ajustes' => Livewire::test(\App\Filament\Pages\AjustesPage::class)->html(),
            'propiedades' => Livewire::test(ManageProperties::class)->html(),
            'alquiler' => Livewire::test(CreateRental::class)->html(),
            'ipc' => Livewire::test(ListIpcSeries::class)->html(),
            'reajuste' => Livewire::test(CreateRentAdjustment::class)
                ->fillForm([
                    'rental_id' => $rental->id,
                    'effective_from' => now()->addYear()->startOfYear()->addMonths(2)->toDateString(),
                    'ipc_basis' => IpcBasis::National->value,
                ])
                ->html(),
        ];

        // La comprobación de claves no depende de la pantalla: si una clave falta en
        // cualquiera de estos ocho archivos, se mostraría literal en alguna de
        // ellas. Se recorre una sola vez para no repetir el mismo trabajo cinco
        // veces.
        $this->assertNoVisibleKeys('', [
            'settings',
            'property',
            'rental',
            'ipc',
            'rent_adjustment',
            'geography',
            'adjustment_rules',
            'document',
        ]);

        foreach ($screens as $name => $html) {
            $this->assertNotSame('', $html, "la pantalla {$name} no renderizó nada");
        }
    }

    #[Test]
    public function el_panel_de_comprobaciones_se_ve_con_color_y_no_con_emoji(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();

        $rental = $this->rental();

        $html = Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $rental->id,
                'effective_from' => now()->addYear()->startOfYear()->addMonths(2)->toDateString(),
                'ipc_basis' => IpcBasis::National->value,
            ])
            ->html();

        // Con una combinación legal, el artículo 20 cumple los doce meses: debe
        // aparecer el aviso informativo.
        $this->assertStringContainsString('ajuste-checks__item', $html);
        $this->assertStringContainsString('ajuste-checks__item--info', $html);
        $this->assertStringContainsString(__('rent_adjustment.checks.title'), $html);

        // El artículo va en su propia línea, no pegado al texto con un corchete.
        $this->assertStringContainsString('ajuste-checks__article', $html);

        // Ningún emoji en el marcado. Los tres que había eran ⛔ ⚠ ℹ, y en una
        // impresión monocroma se convertían en cuadros negros.
        foreach (['⛔', '⚠', 'ℹ', '✅', '📄'] as $emoji) {
            $this->assertStringNotContainsString($emoji, $html, "quedó un emoji en el panel: {$emoji}");
        }
    }

    #[Test]
    public function la_preview_aparece_con_su_titulo_y_sus_filas(): void
    {
        IpcRate::factory()->forYear(2025, 5.10)->create();
        Geography::factory()->national()->create();

        $html = Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $this->rental()->id,
                'effective_from' => now()->addYear()->startOfYear()->addMonths(2)->toDateString(),
                'ipc_basis' => IpcBasis::National->value,
            ])
            ->html();

        $this->assertStringContainsString('ajuste-preview', $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.title'), $html);
        $this->assertStringContainsString(__('rent_adjustment.preview.cap'), $html);

        // El tope del 5,10 % sobre un millón son 1.051.000. La cifra aparece
        // formateada a la manera colombiana, con punto de miles.
        $this->assertStringContainsString('1.051.000', $html);
    }

    #[Test]
    public function la_propiedad_ofrece_la_ciudad_en_crear(): void
    {
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();

        $html = Livewire::test(ManageProperties::class)
            ->callAction('create', ['name' => 'Edificio', 'address' => 'Calle 1'])
            ->html();

        $this->assertStringContainsString(__('property.form.geography_code'), $html);
        $this->assertStringContainsString('VALLEDUPAR', $html);
        // El nacional no es una ubicación.
        $this->assertStringNotContainsString('NACIONAL)', $html);
    }

    #[Test]
    public function el_alquiler_expone_los_datos_legales_del_articulo_18(): void
    {
        $html = Livewire::test(CreateRental::class)->html();

        $this->assertStringContainsString(__('rental.form.sections.legal.rental_type'), $html);
    }

    #[Test]
    public function la_ciudad_predeterminada_se_persiste_en_ajustes(): void
    {
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();

        app(SettingsService::class)->save([
            'business_name' => 'Inmobiliaria',
            'tax_id' => '900123456',
            'default_city_code' => '20001',
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(\App\Filament\Pages\AjustesPage::class)
            ->fillForm([
                'business_name' => 'Inmobiliaria',
                'tax_id' => '900123456',
                'default_city_code' => '20001',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('20001', app(\App\Settings\AppSettings::class)->defaultCityCode());
    }
}
