<?php

namespace Database\Factories;

use App\Models\DeliveryAct;
use App\Models\Rental;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAct>
 */
class DeliveryActFactory extends Factory
{
    public function definition(): array
    {
        $rental = Rental::inRandomOrder()->first() ?? Rental::factory()->create();

        return [
            'type' => 'entrega',
            'landlord_name' => $this->faker->name(),
            'landlord_document' => $this->faker->numerify('#########'),
            'tenant_name' => $this->faker->name(),
            'tenant_document' => $this->faker->numerify('#########'),
            'occurred_at' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'scheduled_at' => null,
            'water_reading' => null,
            'energy_reading' => null,
            'gas_reading' => null,
            'commitments' => null,
            'observations' => null,
            'landlord_signature_path' => null,
            'tenant_signature_path' => null,
            'signed_at' => null,
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'landlord_signature_path' => 'signatures/landlord.png',
            'tenant_signature_path' => 'signatures/tenant.png',
            'signed_at' => now(),
        ]);
    }

    public function withMeters(): static
    {
        return $this->state(fn () => [
            'water_reading' => (string) $this->faker->numberBetween(100, 900),
            'energy_reading' => (string) $this->faker->numberBetween(1000, 9000),
        ]);
    }
}