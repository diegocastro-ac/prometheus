<?php

namespace App\Iterators;

use App\Models\SpaceComponent;
use Iterator;
use OutOfBoundsException;

/**
 * Recorrido en preorden del arbol de espacios (patron Iterator).
 *
 * El iterador guarda una pila de nodos pendientes con su profundidad y su
 * padre. Asi los clientes reciben el mismo orden que antes resolvian con
 * recursion, pero sin conocer la forma del arbol ni repetir el recorrido.
 *
 * @implements Iterator<int, SpaceComponent>
 */
final class SpaceTreeIterator implements Iterator
{
    /**
     * @var list<array{node: SpaceComponent, depth: int, parent: ?SpaceComponent}>
     */
    private array $stack = [];

    private int $position = 0;

    /**
     * @param  list<SpaceComponent>  $roots
     */
    public function __construct(private readonly array $roots)
    {
        $this->rewind();
    }

    /**
     * @param  list<SpaceComponent>  $roots
     */
    public static function roots(array $roots): self
    {
        return new self($roots);
    }

    public function rewind(): void
    {
        $this->stack = [];
        $this->position = 0;

        foreach (array_reverse($this->roots) as $root) {
            $this->stack[] = ['node' => $root, 'depth' => 0, 'parent' => null];
        }
    }

    public function valid(): bool
    {
        return $this->stack !== [];
    }

    public function current(): SpaceComponent
    {
        return $this->frame()['node'];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        if ($this->stack === []) {
            return;
        }

        $frame = array_pop($this->stack);
        $this->position++;

        foreach (array_reverse($frame['node']->children()) as $child) {
            $this->stack[] = [
                'node' => $child,
                'depth' => $frame['depth'] + 1,
                'parent' => $frame['node'],
            ];
        }
    }

    /**
     * Profundidad del nodo actual: 0 para las raices.
     */
    public function depth(): int
    {
        return $this->frame()['depth'];
    }

    /**
     * Padre del nodo actual; null para las raices.
     */
    public function parent(): ?SpaceComponent
    {
        return $this->frame()['parent'];
    }

    /**
     * @return array{node: SpaceComponent, depth: int, parent: ?SpaceComponent}
     */
    private function frame(): array
    {
        $frame = $this->stack[array_key_last($this->stack)] ?? null;

        if ($frame === null) {
            throw new OutOfBoundsException('El iterador no tiene un nodo actual.');
        }

        return $frame;
    }
}
