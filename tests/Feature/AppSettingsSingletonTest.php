<?php

namespace Tests\Feature;

use App\Contracts\CurrentUserContextInterface;
use App\Infrastructure\AuthUserContext;
use App\Services\SettingsService;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AppSettings es un singleton de verdad: el contenedor entrega la misma
 * instancia y solo hay una forma de construirla.
 */
class AppSettingsSingletonTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(array $overrides = []): void
    {
        DB::table('app_settings')->insert(array_merge([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'address' => 'Calle 1 # 2-3',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'space_catalog' => json_encode(['Sala', 'Cocina', 'Baño']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function el_contenedor_entrega_la_misma_instancia_de_app_settings(): void
    {
        $this->seedSettings();

        $first = app(AppSettings::class);
        $second = app(AppSettings::class);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function la_configuracion_se_lee_una_sola_vez_desde_la_base_de_datos(): void
    {
        $this->seedSettings();

        $this->assertSame('Inmobiliaria Ejemplo S.A.S.', app(AppSettings::class)->businessName());

        // Si la instancia unica se relee en cada llamada, este cambio se veria.
        DB::table('app_settings')->where('id', 1)->update(['business_name' => 'Otro nombre']);

        $this->assertSame('Inmobiliaria Ejemplo S.A.S.', app(AppSettings::class)->businessName());
    }

    #[Test]
    public function el_contexto_de_usuario_tambien_es_una_instancia_unica(): void
    {
        $this->assertSame(
            app(CurrentUserContextInterface::class),
            app(CurrentUserContextInterface::class),
        );

        $this->assertInstanceOf(AuthUserContext::class, app(CurrentUserContextInterface::class));
    }

    #[Test]
    public function guardar_ajustes_descarta_la_instancia_cacheada(): void
    {
        $this->seedSettings();

        $before = app(AppSettings::class);
        $this->assertSame('Inmobiliaria Ejemplo S.A.S.', $before->businessName());

        app(SettingsService::class)->save([
            'business_name' => 'Inmobiliaria Nueva S.A.S.',
            'tax_id' => '900987654-7',
            'address' => 'Carrera 5 # 6-7',
            'currency' => 'COP',
            'invoice_due_days' => 10,
            'space_catalog' => ['Sala', 'Cocina', 'Baño', 'Terraza'],
        ]);

        $after = app(AppSettings::class);

        $this->assertNotSame($before, $after);
        $this->assertSame('Inmobiliaria Nueva S.A.S.', $after->businessName());
        $this->assertSame('900987654-7', $after->taxId());
        $this->assertSame(10, $after->invoiceDueDays());
        $this->assertSame(['Sala', 'Cocina', 'Baño', 'Terraza'], $after->spaceCatalog());
    }

    #[Test]
    public function sin_fila_guardada_arranca_con_valores_por_defecto(): void
    {
        $settings = app(AppSettings::class);

        $this->assertSame(config('app.name'), $settings->businessName());
        $this->assertSame([], $settings->spaceCatalog());
        $this->assertSame(5, $settings->invoiceDueDays());
    }

    #[Test]
    public function el_formato_de_dinero_usa_la_moneda_configurada(): void
    {
        $this->seedSettings(['currency' => 'COP']);

        $this->assertSame('COP 1.500.000', app(AppSettings::class)->formatMoney(1500000));
    }

    #[Test]
    public function el_espacios_vienen_limpios_sin_espacios_en_blanco(): void
    {
        $this->seedSettings([
            'space_catalog' => json_encode(['  Sala  ', '', 'Cocina', null]),
        ]);

        $this->assertSame(['Sala', 'Cocina'], app(AppSettings::class)->spaceCatalog());
    }
}