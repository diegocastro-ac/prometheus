<?php

namespace Database\Factories;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    protected $model = Space::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => $this->faker->randomElement([
                'Sala', 'Cocina', 'Bano', 'Terraza', 'Patio', 'Garaje', 'Estudio',
            ]).' '.++self::$sequence,
        ];
    }

    public function childOf(Space $parent): static
    {
        return $this->state(fn (): array => [
            'user_id' => $parent->user_id,
            'parent_id' => $parent->id,
        ]);
    }
}
