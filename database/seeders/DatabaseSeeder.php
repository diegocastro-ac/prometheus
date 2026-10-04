<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the default users for testing
        User::factory()->create([
            'name' => 'admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('admin'),
        ]);

        User::factory()->create([
            'name' => 'user',
            'email' => 'user@user.com',
            'password' => Hash::make('user'),
        ]);

        $this->call([
            // El IPC va primero: los reajustes y las cartas que citan esa
            // fuente dependen de que el año este disponible.
            IpcRateSeeder::class,
            TenantSeeder::class,
            PropertySeeder::class,
            RentalSeeder::class,
            PaymentSeeder::class,

            // Los reajustes van al final porque se apoyan en un alquiler con
            // canon vigente y en el IPC del año anterior.
            RentAdjustmentSeeder::class,
        ]);
    }
}
