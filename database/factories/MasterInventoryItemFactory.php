<?php

namespace Database\Factories;

use App\Models\MasterInventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MasterInventoryItem>
 */
class MasterInventoryItemFactory extends Factory
{
    protected $model = MasterInventoryItem::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->randomElement([
                'Refrigerador', 'Lavadora', 'Estufa', 'Mesa de comedor',
                'Sofa', 'Televisor', 'Cama', 'Armario', 'Lampara',
            ]).' '.++self::$sequence,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }
}
