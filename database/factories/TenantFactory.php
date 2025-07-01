<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use \App\Models\User;


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
            'document' => $this->faker->unique()->numerify(str_repeat('#',10)),
            'name' => $this->faker->name(),
            'phone_namber' => $this->faker->unique()->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),

            'user_id' => User::inRandomOrder()->first()->id,
        ];
    }
}
