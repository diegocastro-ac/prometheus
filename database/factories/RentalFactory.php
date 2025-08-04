<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Rental>
 */
class RentalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Dates and calculation of months
        $start = $this->faker->dateTimeBetween('-1 year', 'now');
        $totalMonths = $this->faker->numberBetween(1, 12);
        $end = (clone $start)->modify("+{$totalMonths} months");

        // Create tenant and property linked to the same user
        $user = User::inRandomOrder()->first();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['user_id' => $user->id]);

        return [
            'name' => Str::limit($this->faker->words(3, true), 50, ''),
            'description' => Str::limit($this->faker->optional()->paragraph(), 255, ''),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'total_months' => $totalMonths,
            'total_persons' =>  (string) $this->faker->optional()->numberBetween(1, 10),
            'monthly_amount' => $this->faker->randomFloat(2, 100_000, 3_000_000),
            'agreement_path' => null,
            'is_active' => $this->faker->boolean(),

            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id
        ];
    }
}
