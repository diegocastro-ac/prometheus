<?php

namespace App\Services\Acts;

use App\Models\ActItem;
use App\Models\Space;
use App\Models\SpaceComponent;
use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;
use Illuminate\Support\Collection;

/**
 * Ensambla el arbol de espacios de un arrendador.
 *
 * La base guarda filas planas con parent_id; este servicio las convierte en la
 * estructura que consumen la pantalla y el acta. Se hace una sola consulta y se
 * enlazan los padres en memoria para que calcular la ruta no dispare una
 * consulta por nivel.
 */
class SpaceTreeBuilder
{
    /**
     * @param  Collection<int, Space>  $spaces
     * @param  Collection<int, ActItem>|null  $items
     * @return list<SpaceComponent>
     */
    public function build(Collection $spaces, ?Collection $items = null): array
    {
        $spaces = $spaces->sortBy('name')->values();
        $byId = $spaces->keyBy(fn (Space $space): int => (int) $space->getKey());

        foreach ($spaces as $space) {
            $parent = $space->parent_id === null
                ? null
                : $byId->get((int) $space->parent_id);

            if ($parent instanceof Space) {
                $space->setRelation('parent', $parent);
            }
        }

        $itemsBySpace = ($items ?? collect())
            ->filter(fn (ActItem $item): bool => $item->space_id !== null)
            ->groupBy(fn (ActItem $item): int => (int) $item->space_id);

        $byParent = $spaces->groupBy(
            fn (Space $space): int => $space->parent_id === null ? 0 : (int) $space->parent_id,
        );

        $build = function (Space $space) use (&$build, $byParent, $itemsBySpace): SpaceComponent {
            $children = ($byParent->get((int) $space->getKey()) ?? collect())
                ->map(fn (Space $child): SpaceComponent => $build($child))
                ->values()
                ->all();

            $ownItems = $itemsBySpace->get((int) $space->getKey(), collect());

            return $children === []
                ? new SpaceLeaf($space, $ownItems)
                : new SpaceComposite($space, $children, $ownItems);
        };

        return $spaces
            ->filter(fn (Space $space): bool => $space->parent_id === null
                || ! $byId->has((int) $space->parent_id))
            ->map(fn (Space $space): SpaceComponent => $build($space))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ActItem>|null  $items
     * @return list<SpaceComponent>
     */
    public function forUser(int|string $userId, ?Collection $items = null): array
    {
        $spaces = Space::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        return $this->build($spaces, $items);
    }

    /**
     * Opciones id => ruta para el selector del inventario.
     *
     * @return array<int, string>
     */
    public function options(int|string $userId): array
    {
        $components = $this->forUser($userId);

        return collect($this->flatten($components))
            ->sortBy(fn (SpaceComponent $component): string => $component->path())
            ->mapWithKeys(fn (SpaceComponent $component): array => [
                (int) $component->space()->getKey() => $component->path(),
            ])
            ->all();
    }

    /**
     * @param  list<SpaceComponent>  $components
     * @return list<SpaceComponent>
     */
    public function flatten(array $components): array
    {
        $flattened = [];

        foreach ($components as $component) {
            $flattened = array_merge($flattened, $component->flatten());
        }

        return $flattened;
    }
}
