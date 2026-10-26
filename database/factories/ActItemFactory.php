<?php

namespace Database\Factories;

use App\Enums\ActItemState;
use App\Models\ActItem;
use App\Models\MasterInventoryItem;
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
            'master_inventory_item_id' => null,
            'name' => $this->faker->randomElement([
                'Refrigerador', 'Lavadora', 'Estufa', 'Mesa de comedor', 'Sofá', 'Luz de techo',
            ]),
            'state' => $this->faker->randomElement(ActItemState::cases()),
            'note' => null,
            'photo_path' => null,
        ];
    }

    public function forMasterItem(MasterInventoryItem $item): static
    {
        return $this->state(fn (): array => [
            'master_inventory_item_id' => $item->id,
            'name' => $item->name(),
        ]);
    }
}
