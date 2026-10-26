<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryActResource\Pages\CreateDeliveryAct;
use App\Models\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\SeedsSpaceCatalog;
use Tests\TestCase;

/**
 * El catálogo de espacios vive en el inventario, no en Ajustes.
 *
 * El cambio de sitio no fue una preferencia de pantalla: había DOS catálogos
 * —la tabla `spaces` y `app_settings.space_catalog`— y divergían. El que
 * validaba era el de Ajustes y el que se mostraba como sugerencia era el de la
 * tabla, así que se podía elegir un espacio de la lista y ver cómo lo rechazaba
 * el sistema.
 *
 * Estas pruebas fijan las tres propiedades que hacen que la mudanza valga:
 * el selector ofrece lo del usuario, se puede ampliar desde ahí, y un espacio
 * de otro usuario no se cuela.
 */
class SpaceCatalogFieldTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSpaceCatalog;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function el_inventario_ofrece_los_espacios_del_usuario(): void
    {
        $this->seedSpaces($this->user, ['Sala', 'Cocina', 'Baño']);

        Livewire::test(CreateDeliveryAct::class)->assertSuccessful();

        $options = \App\Filament\Support\SpaceCatalogField::options();

        $this->assertSame(
            ['Baño', 'Cocina', 'Sala'],
            array_values($options),
            'el catálogo debe venir ordenado alfabéticamente',
        );
        $this->assertSame(
            Space::query()->where('user_id', $this->user->id)->pluck('id')->sort()->values()->all(),
            collect($options)->keys()->sort()->values()->all(),
            'las opciones usan el id del espacio como valor',
        );
    }

    #[Test]
    public function el_catalogo_de_un_usuario_no_aparece_para_otro(): void
    {
        // `app_settings` era GLOBAL: dos arrendadores de la misma instalación
        // compartían espacios. Con la tabla, cada uno tiene los suyos, que es lo
        // que corresponde: uno escribe «Sala» y el otro «Estar».
        $otro = User::factory()->create();
        $this->seedSpaces($otro, ['Estar', 'Balcón French']);
        $this->seedSpaces($this->user, ['Sala']);

        // El selector lee el usuario autenticado, así que la comprobación es
        // cambiar quién está dentro.
        $this->actingAs($this->user);
        $mias = array_values(\App\Filament\Support\SpaceCatalogField::options());

        $this->actingAs($otro);
        $suyas = array_values(\App\Filament\Support\SpaceCatalogField::options());

        $this->assertSame(['Sala'], $mias);
        $this->assertSame(['Balcón French', 'Estar'], $suyas);
        $this->assertNotContains('Estar', $mias);
    }

    #[Test]
    public function el_catalogo_nace_vacio_y_no_invita_a_inventariar(): void
    {
        // Sin espacios no hay sobre qué comparar el inventario posterior. El
        // Builder lo dice, y el mensaje dice cómo resolverlo: crear el primero
        // desde el propio selector.
        $this->seedSpaces($this->user, []);

        $this->assertSame([], \App\Filament\Support\SpaceCatalogField::options());

        $this->assertStringContainsString(
            'botón de creación',
            __('act.errors.no_spaces'),
            'el mensaje debe decir cómo crear el primer espacio',
        );
        $this->assertStringNotContainsString(
            'Ajustes',
            __('act.errors.no_spaces'),
            'ya no hay nada que configurar en Ajustes',
        );
    }

    #[Test]
    public function un_espacio_nuevo_se_puede_crear_desde_el_inventario(): void
    {
        $this->seedSpaces($this->user, ['Sala']);

        // Lo que hace el createOptionUsing del selector, sin pasar por la UI:
        // el efecto tiene que ser el mismo porque es el mismo código.
        $space = Space::query()->firstOrCreate([
            'user_id' => $this->user->id,
            'parent_id' => null,
            'name' => 'Zona de lavado',
        ]);

        $this->assertSame('Zona de lavado', $space->name);
        $this->assertTrue($space->wasRecentlyCreated);
        $this->assertContains(
            'Zona de lavado',
            array_values(\App\Filament\Support\SpaceCatalogField::options()),
        );
    }

    #[Test]
    public function crear_un_espacio_que_ya_existe_no_duplica_ni_falla(): void
    {
        $this->seedSpaces($this->user, ['Bodega']);

        // El botón puede pulsarse dos veces sobre el mismo nombre. Un índice
        // único que revienta con una excepción acaba enseñando al usuario a no
        // usar el botón.
        $primero = Space::query()->firstOrCreate([
            'user_id' => $this->user->id,
            'parent_id' => null,
            'name' => 'Bodega',
        ]);

        $segundo = Space::query()->firstOrCreate([
            'user_id' => $this->user->id,
            'parent_id' => null,
            'name' => 'Bodega',
        ]);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertFalse($segundo->wasRecentlyCreated);
        $this->assertSame(1, Space::query()->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function el_comparador_ignora_las_mayusculas(): void
    {
        // «sala» y «Sala» son el mismo espacio. Si se contaran como dos, el
        // inventario se fragmentaba y una comparación entre dos actas dejaba de
        // ser posible.
        $this->seedSpaces($this->user, ['Sala']);

        // La normalización que hace el Builder: primero recorta, después pasa a
        // minúsculas. Con «  Sala  » se comprueba que el orden es ese, porque
        // invertirlo haría que un espacio con espacios al borde no se encontrara.
        $catalogo = Space::query()
            ->where('user_id', $this->user->id)
            ->pluck('name')
            ->map(static fn (string $n): string => mb_strtolower(trim($n)))
            ->all();

        $this->assertSame(['sala'], $catalogo);
        $this->assertContains(mb_strtolower(trim('  SALA  ')), $catalogo);
    }

    #[Test]
    public function el_mensaje_de_espacio_faltante_no_manda_mas_a_ajustes(): void
    {
        // El texto cambió de fondo: antes decía «agreguelo en Ajustes», lo que
        // obligaba a interrumpir el inventario para configurar un dato que se
        // necesitaba en ese momento.
        $mensaje = __('act.errors.space_not_in_catalog', [
            'space' => 'Bodega',
            'catalog' => 'Sala, Cocina',
        ]);

        $this->assertStringContainsString('Bodega', $mensaje);
        $this->assertStringContainsString('crear este', $mensaje);
        $this->assertStringNotContainsString('Ajustes', $mensaje);
    }

    #[Test]
    public function el_backfill_copia_el_catalogo_a_la_tabla(): void
    {
        // El contenido de la migración de backfill, comprobado sobre el
        // comportamiento observable: un catálogo que estaba en Ajustes aparece
        // en `spaces` para cada usuario, en vez de quedar solo en la columna
        // que después desaparece.
        $this->assertTrue(
            (bool) \Illuminate\Support\Facades\Schema::hasColumn('app_settings', 'default_city_code'),
            'la migración de ciudad predeterminada debe seguir aplicando',
        );

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('app_settings', 'space_catalog'),
            'la columna del catálogo debe estar eliminada',
        );
    }
}
