<?php

namespace Tests\Feature;

use App\Iterators\SpaceTreeIterator;
use App\Models\Space;
use App\Models\User;
use App\Services\Acts\SpaceTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El recorrido del arbol de espacios como iterador.
 *
 * Lo que se fija aqui es el contrato que reemplazo a las recursiones
 * dispersas: preorden, profundidad y padre, con el mismo orden de hijos que
 * devuelve el Composite.
 */
class SpaceTreeIteratorTest extends TestCase
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
    public function recorre_en_preorden_con_profundidad_y_padre(): void
    {
        $iterator = SpaceTreeIterator::roots(
            app(SpaceTreeBuilder::class)->forUser($this->user->id),
        );

        $nodes = [];

        foreach ($iterator as $node) {
            $nodes[] = [
                'name' => $node->name(),
                'depth' => $iterator->depth(),
                'parent' => $iterator->parent()?->name(),
            ];
        }

        $this->assertSame([
            ['name' => 'Casa', 'depth' => 0, 'parent' => null],
            ['name' => 'Cocina', 'depth' => 1, 'parent' => 'Casa'],
            ['name' => 'Alacena', 'depth' => 2, 'parent' => 'Cocina'],
            ['name' => 'Oficina', 'depth' => 0, 'parent' => null],
        ], $nodes);
    }

    #[Test]
    public function el_componente_es_iterable_y_coincide_con_flatten(): void
    {
        $tree = app(SpaceTreeBuilder::class)->forUser($this->user->id);
        $casa = $tree[0];

        $names = [];

        foreach ($casa as $node) {
            $names[] = $node->name();
        }

        $this->assertSame(['Casa', 'Cocina', 'Alacena'], $names);
        $this->assertSame(
            ['Casa', 'Cocina', 'Alacena'],
            array_map(fn ($node): string => $node->name(), $casa->flatten()),
        );
    }

    #[Test]
    public function un_conjunto_vacio_no_emite_nodos(): void
    {
        $iterator = SpaceTreeIterator::roots([]);

        $this->assertFalse($iterator->valid());
        $this->assertSame([], iterator_to_array($iterator, false));
    }

    #[Test]
    public function las_raices_conservan_su_orden_y_no_tienen_padre(): void
    {
        $iterator = SpaceTreeIterator::roots(
            app(SpaceTreeBuilder::class)->forUser($this->user->id),
        );

        $roots = [];

        foreach ($iterator as $node) {
            if ($iterator->depth() === 0) {
                $roots[] = [$node->name(), $iterator->parent()];
            }
        }

        $this->assertSame(['Casa', 'Oficina'], array_column($roots, 0));
        $this->assertSame([null, null], array_column($roots, 1));
    }
}
