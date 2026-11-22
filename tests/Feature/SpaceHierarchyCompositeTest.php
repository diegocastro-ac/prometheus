<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Space;
use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;
use App\Models\User;
use App\Services\Acts\DeliveryActBuilder;
use App\Services\Acts\SpaceTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SpaceHierarchyCompositeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Space $casa;

    private Space $cocina;

    private Space $alacena;

    private Space $oficina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->casa = $this->space('Casa');
        $this->cocina = $this->space('Cocina', $this->casa);
        $this->alacena = $this->space('Alacena', $this->cocina);
        $this->oficina = $this->space('Oficina');
    }

    private function space(string $name, ?Space $parent = null): Space
    {
        return Space::factory()->create([
            'user_id' => $this->user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);
    }

    #[Test]
    public function el_arbol_trata_igual_una_hoja_y_un_compuesto(): void
    {
        $tree = app(SpaceTreeBuilder::class)->forUser($this->user->id);

        $this->assertCount(2, $tree);
        $this->assertInstanceOf(SpaceComposite::class, $tree[0]);
        $this->assertInstanceOf(SpaceLeaf::class, $tree[1]);

        $nodes = collect(app(SpaceTreeBuilder::class)->flatten($tree))->keyBy(
            fn ($node): string => $node->name(),
        );

        $this->assertTrue($nodes['Casa']->isComposite());
        $this->assertTrue($nodes['Cocina']->isComposite());
        $this->assertFalse($nodes['Alacena']->isComposite());
        $this->assertSame('Casa / Cocina / Alacena', $nodes['Alacena']->path());
        $this->assertSame('Oficina', $nodes['Oficina']->path());
    }

    #[Test]
    public function las_opciones_usan_el_id_y_muestran_la_ruta(): void
    {
        $options = app(SpaceTreeBuilder::class)->options($this->user->id);

        $this->assertSame('Casa', $options[$this->casa->id]);
        $this->assertSame('Casa / Cocina', $options[$this->cocina->id]);
        $this->assertSame('Casa / Cocina / Alacena', $options[$this->alacena->id]);
        $this->assertSame('Oficina', $options[$this->oficina->id]);
    }

    #[Test]
    public function las_opciones_del_selector_muestran_la_jerarquia_con_guias(): void
    {
        $options = app(SpaceTreeBuilder::class)->treeOptions($this->user->id);

        $this->assertSame([
            $this->casa->id => 'Casa',
            $this->cocina->id => '— Casa / Cocina',
            $this->alacena->id => '— — Casa / Cocina / Alacena',
            $this->oficina->id => 'Oficina',
        ], $options);
    }

    #[Test]
    public function una_raiz_no_puede_repetir_nombre_pero_una_rama_si(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('act.errors.space_duplicate'));

        $this->space('Casa');
    }

    #[Test]
    public function dos_ramas_pueden_tener_el_mismo_nombre(): void
    {
        $otraCasa = $this->space('Casa', $this->oficina);

        $this->assertSame('Oficina / Casa', $otraCasa->path());
        $this->assertNotSame($this->casa->id, $otraCasa->id);
    }

    #[Test]
    public function mover_un_espacio_bajo_su_descendiente_crea_un_ciclo_y_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('act.errors.invalid_space_parent'));

        $this->casa->moveTo($this->alacena->id);
    }

    #[Test]
    public function el_acta_guarda_la_referencia_y_el_snapshot_y_arma_el_arbol(): void
    {
        $rental = Rental::factory()->create(['user_id' => $this->user->id]);

        $act = app(DeliveryActBuilder::class)
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([
                ['space_id' => $this->cocina->id, 'name' => 'Estufa'],
                ['space_id' => $this->alacena->id, 'name' => 'Arroz'],
                ['space_id' => $this->alacena->id, 'name' => 'Lentejas'],
            ])
            ->build();

        $this->assertSame(
            [$this->cocina->id, $this->alacena->id, $this->alacena->id],
            $act->items->pluck('space_id')->all(),
        );
        $this->assertSame('Cocina', $act->items[0]->space);
        $this->assertSame('Alacena', $act->items[1]->space);
        $this->assertSame(
            ['Casa / Cocina' => 1, 'Casa / Cocina / Alacena' => 2],
            array_map('count', $act->itemsBySpacePath()),
        );

        $tree = $act->inventoryTree();
        $casa = collect(app(SpaceTreeBuilder::class)->flatten($tree))
            ->firstWhere(fn ($node): bool => $node->name() === 'Casa');

        $this->assertSame(3, $casa->totalItems());

        $html = view('filament.acts.inventory-tree', [
            'nodes' => $tree,
            'depth' => 0,
        ])->render();

        $this->assertStringContainsString('Casa', $html);
        $this->assertStringContainsString('Arroz', $html);
    }

    #[Test]
    public function un_espacio_de_otro_arrendador_no_se_acepta_por_id(): void
    {
        $otro = User::factory()->create();
        $ajeno = Space::factory()->create([
            'user_id' => $otro->id,
            'name' => 'Ajeno',
        ]);

        $rental = Rental::factory()->create(['user_id' => $this->user->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('act.errors.space_not_in_catalog', [
            'space' => 'Ajeno',
            'catalog' => 'Casa, Casa / Cocina, Casa / Cocina / Alacena, Oficina',
        ]));

        app(DeliveryActBuilder::class)
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([
                ['space_id' => $ajeno->id, 'space' => 'Ajeno', 'name' => 'Silla'],
            ]);
    }
}
