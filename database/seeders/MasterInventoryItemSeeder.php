<?php

namespace Database\Seeders;

use App\Models\MasterInventoryItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class MasterInventoryItemSeeder extends Seeder
{
    /**
     * Catalogo base para cada arrendador de la aplicacion.
     */
    public function run(): void
    {
        $names = [
            'Refrigerador',
            'Lavadora',
            'Estufa',
            'Mesa de comedor',
            'Sofa',
            'Televisor',
            'Cama',
            'Armario',
            'Lampara',
            'Aire acondicionado',
        ];

        User::query()->each(function (User $user) use ($names): void {
            foreach ($names as $name) {
                MasterInventoryItem::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'name' => $name,
                ]);
            }
        });
    }
}
