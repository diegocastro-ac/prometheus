<?php

namespace App\Contracts;

/**
 * Estado intrinseco compartido de un elemento de inventario.
 *
 * El elemento dice que es (id, nombre y arrendador dueno). Lo que cambia en
 * cada acta (espacio, estado, nota y foto) queda fuera del flyweight.
 */
interface InventoryItemFlyweight
{
    public function id(): int|string|null;

    public function name(): string;

    public function ownerId(): int|string|null;
}
