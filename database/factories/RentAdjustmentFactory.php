<?php

namespace Database\Factories;

use App\Models\RentAdjustment;
use App\Models\Rental;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentAdjustment>
 */
class RentAdjustmentFactory extends Factory
{
    protected $model = RentAdjustment::class;

    public function definition(): array
    {
        $previous = fake()->numberBetween(400_000, 2_000_000);
        $percentage = fake()->randomFloat(2, 1, 20);

        return [
            'rental_id' => Rental::factory(),

            // El arrearsgo se deduce del alquiler. Escribirlo aparte
            // permitiria un reajuste que apunta a un alquiler de otro
            // arrendador, que es justo el dato que hay que mantener coherente.
            'user_id' => fn (array $attributes): int => (int) Rental::find($attributes['rental_id'])->user_id,

            'ipc_rate_id' => IpcRateFactory::new(),
            'previous_rent' => $previous,
            'new_rent' => round($previous * (1 + $percentage / 100)),
            'ipc_year' => fake()->numberBetween(2015, 2025),
            'ipc_percentage' => $percentage,
            'legal_cap_rent' => round($previous * (1 + $percentage / 100)),
            'excess_over_cap' => 0,
            'requires_written_agreement' => false,
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'notified_on' => null,
            'notes' => null,
        ];
    }

    /**
     * Reajuste dentro del tope del IPC.
     */
    public function withinCap(): self
    {
        return $this->state(function (array $attributes): array {
            $cap = self::capFor((float) $attributes['previous_rent'], (float) $attributes['ipc_percentage']);

            return [
                'new_rent' => $cap,
                'legal_cap_rent' => $cap,
                'excess_over_cap' => 0,
                'requires_written_agreement' => false,
            ];
        });
    }

    /**
     * Reajuste por encima del IPC, que solo opera con acuerdo escrito.
     */
    public function exceedingCap(float $excess = 150_000): self
    {
        return $this->state(function (array $attributes) use ($excess): array {
            $cap = self::capFor((float) $attributes['previous_rent'], (float) $attributes['ipc_percentage']);

            return [
                'legal_cap_rent' => $cap,
                'new_rent' => $cap + $excess,
                'excess_over_cap' => $excess,
                'requires_written_agreement' => true,
            ];
        });
    }

    private static function capFor(float $previousRent, float $percentage): float
    {
        return round($previousRent * (1 + $percentage / 100));
    }
}
