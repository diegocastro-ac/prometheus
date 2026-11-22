<?php

namespace Tests;

use App\Models\MasterInventoryItem;
use App\Models\User;

/**
 * Siembra el catalogo maestro de elementos del arrendador.
 *
 * Es el equivalente de SeedsSpaceCatalog para el flyweight: varias pruebas
 * necesitan los mismos elementos sin repetir el firstOrCreate.
 */
trait SeedsMasterInventoryCatalog
{
    /**
     * @param  list<string>  $names
     * @return array<string, MasterInventoryItem>
     */
    protected function seedMasterInventory(User $user, array $names = [
        'Refrigerador', 'Lavadora', 'Estufa', 'Televisor',
    ]): array
    {
        $items = [];

        foreach ($names as $name) {
            $items[$name] = MasterInventoryItem::query()->firstOrCreate([
                'user_id' => $user->id,
                'name' => $name,
            ]);
        }

        return $items;
    }

    protected function masterInventoryItem(User $user, string $name): MasterInventoryItem
    {
        return MasterInventoryItem::query()->firstOrCreate([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
