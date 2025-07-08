<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use \App\Models\User;
use Illuminate\Support\Str;


/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document' => $this->faker->unique()->numerify(str_repeat('#', 15)),
            'name' => Str::limit($this->faker->name(), 50, ''),
            'phone_number' => Str::limit($this->faker->unique()->phoneNumber(), 15, ''),
            'email' => Str::limit($this->faker->unique()->optional()->safeEmail(), 100, ''),

            'user_id' => User::inRandomOrder()->first()->id,
        ];
    }
}
