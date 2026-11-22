<?php

namespace Database\Seeders;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Seeder;

class SpaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();

        if (! $user) {
            return;
        }

        $tree = [
            'Interiores' => [
                'Sala',
                'Cocina',
                'Dormitorio principal',
                'Baño',
            ],
            'Exteriores' => [
                'Balcón',
                'Patio',
                'Garaje',
                'Terraza',
                'Lavadero',
                'Jardín',
            ],
        ];

        foreach ($tree as $rootName => $children) {
            $root = Space::firstOrCreate([
                'name' => $rootName,
                'user_id' => $user->id,
                'parent_id' => null,
            ]);

            foreach ($children as $childName) {
                Space::firstOrCreate([
                    'name' => $childName,
                    'user_id' => $user->id,
                    'parent_id' => $root->id,
                ]);
            }
        }
    }
}
