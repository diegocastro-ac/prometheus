<?php

namespace Database\Seeders;

use App\Models\Rental;
use App\ValueObjects\RentalPeriod;
use Illuminate\Database\Seeder;

class RentalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Rental::factory(10)->create();

        /*
         * El factory sortea el inicio dentro del ultimo ano, asi que ninguno de
         * esos alquileres ha cumplido los doce meses bajo un mismo precio y
         * ninguno esta en fecha legal de reajuste. Los dos de demostracion
         * empiezan hace dos anos para que RentAdjustmentSeeder tenga un caso
         * real que sembrar: uno para el reajuste dentro del tope y otro para el
         * que se aparta del tope con acuerdo escrito.
         */
        $start = now()->subYears(2)->startOfDay();

        foreach ([['Alquiler demo 1', 1_500_000], ['Alquiler demo 2', 1_200_000]] as [$name, $amount]) {
            Rental::factory()->create([
                'name' => $name,
                'start_date' => $start->toDateString(),
                'end_date' => RentalPeriod::for($start->copy(), 24)->endDateFormatted(),
                'total_months' => 24,
                'monthly_amount' => $amount,
                'anchor_monthly_amount' => $amount,
                'anchor_established_on' => $start->toDateString(),
                'is_active' => true,
            ]);
        }
    }
}
