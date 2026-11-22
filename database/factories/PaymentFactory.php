<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $rental = Rental::inRandomOrder()->first() ?? Rental::factory()->create();

        $date = $this->faker->dateTimeBetween($rental->start_date, Carbon::parse($rental->end_date)->addMonth());

        return [
            'date' => $date->format('Y-m-d'),
            'amount' => $this->faker->randomFloat(2, 50_000, 3_000_000),
            'method' => $this->faker->randomElement(['efectivo', 'transferencia', 'consignacion']),
            'reference' => null,

            'invoice_id' => null,
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ];
    }

    /**
     * Un pago que cubre por completo la factura que referencia.
     */
    public function forInvoice(?Invoice $invoice = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'invoice_id' => ($invoice ?? Invoice::factory()->create([
                'rental_id' => $attributes['rental_id'],
                'user_id' => $attributes['user_id'],
            ]))->id,
            'amount' => $invoice?->amount ?? $this->faker->randomFloat(2, 100_000, 2_000_000),
        ]);
    }
}
