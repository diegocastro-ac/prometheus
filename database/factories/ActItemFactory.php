<?php

namespace Database\Factories;

use App\Enums\ActItemState;
use App\Models\ActItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActItem>
 */
class ActItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'space' => $this->faker->randomElement(['Sala', 'Cocina', 'Baño', 'Terraza']),
            'name' => $this->faker->randomElement([
                'Refrigerador', 'Lavadora', 'Estufa', 'Mesa de comedor', 'Sofá', 'Luz de techo',
            ]),
            'state' => $this->faker->randomElement(ActItemState::cases()),
            'note' => null,
            'photo_path' => null,
        ];
    }
}