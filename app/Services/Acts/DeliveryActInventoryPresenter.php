<?php

namespace App\Services\Acts;

use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\SpaceComponent;
use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;
use Illuminate\Support\Collection;

/**
 * Prepara el inventario de un acta para la pantalla de consulta.
 *
 * El Composite ya sabe recorrer el arbol completo del arrendador. Esta clase se
 * queda con la parte que interesa a una acta concreta: las ramas que tienen
 * elementos, las filas de la tabla y los datos planos que consume el organigrama.
 *
 * Se filtra aqui y no en el modelo porque el catalogo completo sigue siendo
 * necesario para crear inventarios; lo que cambia es solo lo que se muestra.
 */
final class DeliveryActInventoryPresenter
{
    public const ROOT_ID = 'act-inventory-root';

    /** @var list<SpaceComponent>|null */
    private ?array $tree = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    /** @var Collection<int, ActItem>|null */
    private ?Collection $orphanItems = null;

    public function __construct(private readonly DeliveryAct $act) {}

    /**
     * Solo las ramas con inventario, conservando los ancestros necesarios.
     *
     * @return list<SpaceComponent>
     */
    public function tree(): array
    {
        $this->act->loadMissing('items.spaceNode');

        return $this->tree ??= array_values(array_filter(array_map(
            fn (SpaceComponent $node): ?SpaceComponent => $this->prune($node),
            $this->act->inventoryTree(),
        )));
    }

    /**
     * Recorrido en preorden para el modo tabla.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = [];
        $this->appendRows($this->tree(), 0, $rows);

        return $this->rows = $rows;
    }

    /**
     * Datos planos que consume d3-org-chart.
     *
     * Se agrega una raiz sintetica para que el acta funcione aunque el
     * arrendador tenga varios espacios raiz.
     *
     * @return list<array<string, mixed>>
     */
    public function chartData(): array
    {
        $data = [[
            'id' => self::ROOT_ID,
            'parentId' => '',
            'name' => __('act.view.chart_root'),
            'path' => '',
            'kind' => 'root',
            'directItems' => 0,
            'totalItems' => $this->totalItems(),
            'items' => [],
            'moreItems' => 0,
            'stateCounts' => [],
        ]];

        foreach ($this->tree() as $node) {
            $this->appendChartNode($node, self::ROOT_ID, $data);
        }

        return $data;
    }

    /**
     * Elementos cuyo espacio fue eliminado del catalogo.
     *
     * @return Collection<int, ActItem>
     */
    public function orphanItems(): Collection
    {
        if ($this->orphanItems !== null) {
            return $this->orphanItems;
        }

        $this->act->loadMissing('items.spaceNode');

        return $this->orphanItems = $this->act->items
            ->filter(fn (ActItem $item): bool => $item->space_id === null || $item->spaceNode === null)
            ->values();
    }

    /**
     * @return array{spaces: int, items: int, orphans: int, states: array<string, int>}
     */
    public function stats(): array
    {
        $this->act->loadMissing('items');

        return [
            'spaces' => count($this->rows()),
            'items' => $this->act->items->count(),
            'orphans' => $this->orphanItems()->count(),
            'states' => $this->act->items
                ->groupBy(fn (ActItem $item): string => $item->state?->value ?? 'sin_estado')
                ->map(fn (Collection $items): int => $items->count())
                ->all(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->tree() === [] && $this->orphanItems()->isEmpty();
    }

    private function prune(SpaceComponent $node): ?SpaceComponent
    {
        $children = array_values(array_filter(array_map(
            fn (SpaceComponent $child): ?SpaceComponent => $this->prune($child),
            $node->children(),
        )));

        $items = $node->items();

        if ($children === [] && $items->isEmpty()) {
            return null;
        }

        return $children === []
            ? new SpaceLeaf($node->space(), $items)
            : new SpaceComposite($node->space(), $children, $items);
    }

    /**
     * @param  list<SpaceComponent>  $nodes
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendRows(array $nodes, int $depth, array &$rows): void
    {
        foreach ($nodes as $node) {
            $rows[] = [
                'id' => 'space-'.$node->space()->getKey(),
                'name' => $node->name(),
                'path' => $node->path(),
                'depth' => $depth,
                'composite' => $node->isComposite(),
                'directItems' => $node->items()->count(),
                'totalItems' => $node->totalItems(),
                'items' => $this->serializeItems($node->items()),
                'stateCounts' => $this->stateCounts($node->items()),
            ];

            $this->appendRows($node->children(), $depth + 1, $rows);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function appendChartNode(SpaceComponent $node, string $parentId, array &$data): void
    {
        $items = $node->items();
        $id = 'space-'.$node->space()->getKey();

        $data[] = [
            'id' => $id,
            'parentId' => $parentId,
            'name' => $node->name(),
            'path' => $node->path(),
            'kind' => $node->isComposite() ? 'composite' : 'leaf',
            'directItems' => $items->count(),
            'totalItems' => $node->totalItems(),
            'items' => $this->serializeItems($items->take(3)),
            'moreItems' => max(0, $items->count() - 3),
            'stateCounts' => $this->stateCounts($items),
        ];

        foreach ($node->children() as $child) {
            $this->appendChartNode($child, $id, $data);
        }
    }

    /**
     * @param  Collection<int, ActItem>  $items
     * @return list<array<string, mixed>>
     */
    private function serializeItems(Collection $items): array
    {
        return $items->map(fn (ActItem $item): array => [
            'name' => (string) $item->name,
            'state' => $item->state?->value,
            'stateLabel' => $item->state?->label(),
            'note' => $item->note,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, ActItem>  $items
     * @return array<string, int>
     */
    private function stateCounts(Collection $items): array
    {
        return $items
            ->groupBy(fn (ActItem $item): string => $item->state?->value ?? 'sin_estado')
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    private function totalItems(): int
    {
        return array_sum(array_map(
            fn (SpaceComponent $node): int => $node->totalItems(),
            $this->tree(),
        ));
    }
}
