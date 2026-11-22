<?php

namespace Database\Seeders;

use App\Enums\ActItemState;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\MasterInventoryItem;
use App\Models\Rental;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Seeder;

class DeliveryActSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();
        $rental = Rental::first();

        if (! $user || ! $rental) {
            return;
        }

        $acts = DeliveryAct::factory()->count(3)->create([
            'user_id' => $user->id,
            'rental_id' => $rental->id,
        ]);

        $spaces = Space::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get()
            ->values();

        $items = MasterInventoryItem::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get()
            ->values();

        if ($spaces->isEmpty() || $items->isEmpty()) {
            return;
        }

        foreach ($acts as $actIndex => $act) {
            for ($offset = 0; $offset < 2; $offset++) {
                $space = $spaces->get(($actIndex * 2 + $offset) % $spaces->count());
                $item = $items->get(($actIndex * 2 + $offset) % $items->count());

                ActItem::query()->create([
                    'delivery_act_id' => $act->id,
                    'space_id' => $space->id,
                    'master_inventory_item_id' => $item->id,
                    'space' => $space->name,
                    'name' => $item->name(),
                    'state' => ActItemState::BUENO,
                    'note' => null,
                    'photo_path' => null,
                ]);
            }
        }
    }
}
