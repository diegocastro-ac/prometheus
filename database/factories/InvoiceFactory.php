<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $rental = Rental::inRandomOrder()->first() ?? Rental::factory()->create();

        $issuedAt = $this->faker->dateTimeBetween('-6 months', 'now');

        return [
            // El numero no se inventa aqui. La tabla exige unicidad de
            // (user_id, number) y una cadena aleatoria tarde o temprano choca,
            // sobre todo cuando quien siembra pasa un user_id explicito. Quien
            // necesita un numero real lo pide a InvoiceNumberService; el valor
            // por defecto solo sirve para pruebas aisladas.
            'number' => 'FV-'.$issuedAt->format('Y').'-'.fake()->unique()->numerify('####'),
            // Las claves son las mismas que ofrece el formulario, no el texto
            // traducido: asi un filtro por concepto funciona igual en todo el
            // sistema.
            'concept' => $this->faker->randomElement([
                'rent',
                'services',
                'rent_services',
            ]),
            'amount' => $this->faker->randomFloat(2, 100_000, 2_000_000),
            'period' => Invoice::periodFor($issuedAt),
            'issued_at' => $issuedAt->format('Y-m-d'),
            'due_at' => (clone $issuedAt)->modify('+5 days')->format('Y-m-d'),
            'status' => InvoiceStatus::EMITIDA,
            'paid_at' => null,
            'notes' => null,
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::PAGADA,
            'paid_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'issued_at' => now()->subMonths(2)->toDateString(),
            'due_at' => now()->subMonth()->toDateString(),
            // El periodo se mueve con las fechas: una factura de mayo que se
            // emite en julio sigue siendo de mayo.
            'period' => now()->subMonths(2)->format('Y-m'),
            'status' => InvoiceStatus::VENCIDA,
        ]);
    }

    public function annulled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::ANULADA,
        ]);
    }
}
