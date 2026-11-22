<?php

namespace Database\Factories;

use App\Enums\IpcBasis;
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
            'sequence' => 1,

            // previous_rent es DERIVADO ahora: lo calcula la cadena al
            // reprocesarse desde el ancla. La factory lo fija para que el registro
            // sea coherente por si solo, pero el servicio lo sobreescribe.
            'previous_rent' => $previous,
            'anchor_rent' => $previous,
            'new_rent' => round($previous * (1 + $percentage / 100)),
            'ipc_year' => fake()->numberBetween(2015, 2025),
            'ipc_percentage' => $percentage,

            // Sin pacto, el IPC legal y el aplicado son el mismo numero. El caso
            // del pacto tiene su propio estado mas abajo.
            'legal_pct' => $percentage,
            'applied_pct' => $percentage,
            'ipc_basis' => IpcBasis::National->value,
            'ipc_month' => 12,

            'legal_cap_rent' => round($previous * (1 + $percentage / 100)),
            'excess_over_cap' => 0,
            'requires_written_agreement' => false,
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'notified_on' => null,
            'notification_method' => null,
            'applied_on' => null,
            'notes' => null,
        ];
    }

    /**
     * Reajuste dentro del tope del IPC.
     */
    public function withinCap(): self
    {
        return $this->state(function (array $attributes): array {
            $cap = self::capFor((float) $attributes['previous_rent'], (float) $attributes['legal_pct']);

            return [
                'new_rent' => $cap,
                'legal_cap_rent' => $cap,
                'excess_over_cap' => 0,
                'requires_written_agreement' => false,
            ];
        });
    }

    /**
     * Reajuste por encima de lo que da el IPC.
     *
     * YA NO BLOQUEA. La revision del modulo decidio permitirlo y avisar: el texto
     * del articulo 20 admite las dos lecturas y la jurisprudencia no pudo
     * verificarse al construirlo. El estado se llama "exceedingCap" y no
     * "requiresAgreement" por eso.
     */
    public function exceedingCap(float $excess = 150_000): self
    {
        return $this->state(function (array $attributes) use ($excess): array {
            $cap = self::capFor((float) $attributes['previous_rent'], (float) $attributes['legal_pct']);

            return [
                'legal_cap_rent' => $cap,
                'new_rent' => $cap + $excess,
                'excess_over_cap' => $excess,
                'requires_written_agreement' => true,
            ];
        });
    }

    /**
     * Pactado por debajo del IPC, que el "hasta" del articulo 20 permite.
     */
    public function pactadoBelowCap(float $percentage = 3.0): self
    {
        return $this->state(function (array $attributes) use ($percentage): array {
            $previous = (float) $attributes['previous_rent'];
            $legal = (float) $attributes['legal_pct'];

            return [
                'applied_pct' => $percentage,
                // Lo que se guarda en ipc_percentage es lo aplicado, para que las
                // consultas por esa columna sigan dando el numero que se uso.
                'ipc_percentage' => $percentage,
                // El tope NO baja: es el que fija la ley. Pactar menos cambia la
                // cifra acordada, que es otra cosa.
                'legal_cap_rent' => self::capFor($previous, $legal),
                'new_rent' => self::capFor($previous, $percentage),
                'excess_over_cap' => 0,
            ];
        });
    }

    /**
     * Comunicado por el servicio postal autorizado.
     */
    public function notified(?string $method = 'postal'): self
    {
        return $this->state(fn (): array => [
            'notified_on' => now()->toDateString(),
            'notification_method' => $method,
        ]);
    }

    /**
     * Con el canon nuevo ya vigente en el alquiler.
     */
    public function applied(): self
    {
        return $this->state(fn (): array => [
            'applied_on' => now()->toDateString(),
        ]);
    }

    private static function capFor(float $previousRent, float $percentage): float
    {
        return round($previousRent * (1 + $percentage / 100));
    }
}
