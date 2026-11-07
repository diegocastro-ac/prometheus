<?php

namespace App\Models;

use App\Iterators\SpaceTreeIterator;
use App\Visitors\Space\InventoryReportVisitor;
use App\Visitors\Space\SpaceInventoryReport;
use App\Visitors\Space\SpaceVisitor;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * Component (GoF): el inventario trata igual un espacio hoja y un espacio con
 * subespacios. La pantalla y los calculos no preguntan si un nodo tiene hijos;
 * piden sus elementos, su ruta y su total, y el nodo sabe responder.
 *
 * Tambien es el Aggregate de Iterator: el componente entrega un recorrido en
 * preorden de su subarbol sin exponer la forma de los hijos.
 *
 * Y es el Element de Visitor: accept() redirige la operacion al metodo de la
 * hoja o del compuesto, sin que el cliente pregunte por el tipo.
 *
 * @implements IteratorAggregate<int, SpaceComponent>
 */
abstract class SpaceComponent implements IteratorAggregate
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

    /**
     * Double dispatch de Visitor: el nodo concreto elige el metodo del visitante.
     */
    abstract public function accept(SpaceVisitor $visitor): mixed;

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
        /** @var SpaceInventoryReport $report */
        $report = $this->accept(new InventoryReportVisitor);

        return $report->totalItems;
    }

    /**
     * Recorrido en preorden: el espacio, despues sus subespacios.
     *
     * @return list<SpaceComponent>
     */
    public function flatten(): array
    {
        return iterator_to_array($this->iterator(), false);
    }

    /**
     * Iterador del subarbol que empieza en este componente (patron Iterator).
     */
    public function iterator(): SpaceTreeIterator
    {
        return new SpaceTreeIterator([$this]);
    }

    public function getIterator(): Traversable
    {
        return $this->iterator();
    }
}
