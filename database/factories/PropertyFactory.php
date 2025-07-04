<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::limit($this->faker->words(3, true), 50, ''),
            'description' => Str::limit($this->faker->optional()->paragraph(), 255, ''),
            'address' => Str::limit($this->faker->unique()->address(), 50, ''),
            'is_rented' => $this->faker->boolean(),

            'user_id' => User::inRandomOrder()->first()->id,
        ];
    }
}
