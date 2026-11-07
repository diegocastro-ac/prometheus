<?php

namespace App\Visitors\Space;

use App\Models\ActItem;
use Illuminate\Support\Collection;

/**
 * Resultado de visitar un espacio.
 *
 * Reune lo que la pantalla necesita de cada nodo y lo que el total del subarbol
 * aporta a sus ancestros: el tipo, los elementos directos y el total acumulado.
 */
final readonly class SpaceInventoryReport
{
    /**
     * @param  Collection<int, ActItem>  $items
     */
    public function __construct(
        public string $kind,
        public int $directItems,
        public int $totalItems,
        public Collection $items,
    ) {}
}
