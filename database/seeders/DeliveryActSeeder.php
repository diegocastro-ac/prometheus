<?php

namespace Database\Seeders;

use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeliveryActSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();
        $rental = Rental::first();

        if (!$user || !$rental) {
            return;
        }

        DeliveryAct::factory()->count(3)->create([
            'user_id' => $user->id,
            'rental_id' => $rental->id,
        ]);
    }
}
