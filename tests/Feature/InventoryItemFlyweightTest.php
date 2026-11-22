<?php

namespace Tests\Feature;

use App\Contracts\InventoryItemFlyweight;
use App\Filament\Support\MasterInventoryItemCatalogField;
use App\Models\MasterInventoryItem;
use App\Models\User;
use App\Services\InventoryItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\SeedsMasterInventoryCatalog;
use Tests\TestCase;

/**
 * El catalogo maestro comparte el estado intrinseco del elemento (id, nombre y
 * dueno). El espacio, estado, nota y foto siguen viviendo en cada acta.
 */
class InventoryItemFlyweightTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMasterInventoryCatalog;

    #[Test]
    public function el_catalogo_y_las_opciones_son_por_arrendador(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->seedMasterInventory($user, ['Refrigerador', 'Estufa']);
        $this->seedMasterInventory($other, ['Televisor']);

        $this->actingAs($user);

        $options = MasterInventoryItemCatalogField::options();

        $this->assertSame(['Estufa', 'Refrigerador'], array_values($options));
        $this->assertSame(
            MasterInventoryItem::query()
                ->where('user_id', $user->id)
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
            collect($options)->keys()->sort()->values()->all(),
            'las opciones usan el id del elemento como valor',
        );
        $this->assertNotContains('Televisor', $options);
    }

    #[Test]
    public function la_fabrica_comparte_la_misma_instancia_para_el_mismo_elemento(): void
    {
        $user = User::factory()->create();
        $items = $this->seedMasterInventory($user, ['Refrigerador']);

        $factory = app(InventoryItemFactory::class);

        $byId = $factory->resolve($user->id, $items['Refrigerador']->id);
        $bySameId = $factory->resolve($user->id, (string) $items['Refrigerador']->id);
        $byName = $factory->resolve($user->id, null, '  REFRIGERADOR ');

        $this->assertInstanceOf(InventoryItemFlyweight::class, $byId);
        $this->assertSame($byId, $bySameId);
        $this->assertSame($byId, $byName);
        $this->assertSame($user->id, $byId->ownerId());
    }

    #[Test]
    public function el_pool_es_scoped_y_no_sobrevive_a_la_peticion(): void
    {
        $user = User::factory()->create();
        $items = $this->seedMasterInventory($user, ['Refrigerador']);

        $first = app(InventoryItemFactory::class)->resolve($user->id, $items['Refrigerador']->id);

        app()->forgetScopedInstances();

        $second = app(InventoryItemFactory::class)->resolve($user->id, $items['Refrigerador']->id);

        $this->assertNotSame($first, $second);
        $this->assertSame($first->id(), $second->id());
    }

    #[Test]
    public function un_elemento_no_se_comparte_con_otro_arrendador(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $item = $this->masterInventoryItem($user, 'Refrigerador');

        $factory = app(InventoryItemFactory::class);

        $this->assertNull($factory->find($other->id, $item->id));
        $this->assertSame($other->id, $factory->firstOrCreate($other->id, 'Refrigerador')->ownerId());
    }

    #[Test]
    public function crear_y_renombrar_desde_el_selector_actualiza_el_pool(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $factory = app(InventoryItemFactory::class);

        $item = $factory->firstOrCreate($user->id, 'Microondas');
        $again = $factory->firstOrCreate($user->id, 'microondas');

        $this->assertSame($item->id, $again->id);
        $this->assertContains('Microondas', MasterInventoryItemCatalogField::options());

        $factory->updateName($item, 'Horno microondas');

        $this->assertSame('Horno microondas', MasterInventoryItemCatalogField::labelFor($item->id));
        $this->assertContains('Horno microondas', MasterInventoryItemCatalogField::options());
        $this->assertNotContains('Microondas', MasterInventoryItemCatalogField::options());
    }
}
