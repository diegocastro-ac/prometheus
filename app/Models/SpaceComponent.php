<?php

namespace App\Models;

use Illuminate\Support\Collection;

/**
 * Component (GoF): el inventario trata igual un espacio hoja y un espacio con
 * subespacios. La pantalla y los calculos no preguntan si un nodo tiene hijos;
 * piden sus elementos, su ruta y su total, y el nodo sabe responder.
 */
abstract class SpaceComponent
{
    /**
     * @param  Collection<int, ActItem>  $items
     */
    public function __construct(
        protected readonly Space $space,
        protected readonly Collection $items,
    ) {}

    /**
     * @return list<SpaceComponent>
     */
    abstract public function children(): array;

    abstract public function isComposite(): bool;

    public function space(): Space
    {
        return $this->space;
    }

    public function name(): string
    {
        return $this->space->name;
    }

    public function path(): string
    {
        return $this->space->path();
    }

    /**
     * Solo los elementos colgados directamente de este espacio.
     *
     * @return Collection<int, ActItem>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * @return Collection<int, ActItem>
     */
    public function allItems(): Collection
    {
        $items = new Collection($this->items->all());

        foreach ($this->children() as $child) {
            $items = $items->merge($child->allItems());
        }

        return $items->values();
    }

    public function totalItems(): int
    {
        return $this->items->count()
            + array_sum(array_map(
                static fn (SpaceComponent $child): int => $child->totalItems(),
                $this->children(),
            ));
    }

    /**
     * Recorrido en preorden: el espacio, despues sus subespacios.
     *
     * @return list<SpaceComponent>
     */
    public function flatten(): array
    {
        $nodes = [$this];

        foreach ($this->children() as $child) {
            $nodes = array_merge($nodes, $child->flatten());
        }

        return $nodes;
    }
}
