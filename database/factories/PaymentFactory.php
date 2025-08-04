<?php

namespace Database\Factories;

use App\Models\Rental;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rental = Rental::inRandomOrder()->first() ?? Rental::factory()->create();
        $date = $this->faker->dateTimeBetween($rental->start_date, Carbon::parse($rental->end_date)->addMonth());

        return [
            'date' => $date->format('Y-m-d'),
            'amount' => $this->faker->randomFloat(2, 50_000, 3_000_000),
            'is_rent_paid' => $this->faker->boolean(),
            'rent_voucher_path' => null,
            'is_water_paid' => $this->faker->boolean(),
            'water_voucher_path' => null,
            'is_energy_paid' => $this->faker->boolean(),
            'energy_voucher_path' => null,
            'is_gas_paid' => $this->faker->boolean(),
            'gas_voucher_path' => null,

            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ];
    }
}
