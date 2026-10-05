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

        if (!$user) {
            return;
        }

        $defaultSpaces = [
            'Sala',
            'Cocina',
            'Dormitorio principal',
            'Baño',
            'Balcon',
            'Patio',
            'Garaje',
            'Terraza',
            'Lavandería',
            'Jardín',
        ];

        foreach ($defaultSpaces as $spaceName) {
            Space::firstOrCreate(
                ['name' => $spaceName, 'user_id' => $user->id],
                ['name' => $spaceName, 'user_id' => $user->id]
            );
        }
    }
}
