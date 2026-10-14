<?php

namespace Database\Factories;

use App\Enums\RentalType;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\ValueObjects\RentalPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Rental>
 */
class RentalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Dates and calculation of months
        $start = $this->faker->dateTimeBetween('-1 year', 'now');
        $totalMonths = $this->faker->numberBetween(1, 12);
        $amount = $this->faker->randomFloat(2, 100_000, 3_000_000);

        // Create tenant and property linked to the same user
        $user = User::inRandomOrder()->first();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $property = Property::factory()->create(['user_id' => $user->id]);

        return [
            'name' => Str::limit($this->faker->words(3, true), 50, ''),
            'description' => Str::limit($this->faker->optional()->paragraph(), 255, ''),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => RentalPeriod::for(Carbon::parse($start->format('Y-m-d')), $totalMonths)->endDateFormatted(),
            'total_months' => $totalMonths,
            'total_persons' => (string) $this->faker->optional()->numberBetween(1, 10),
            'monthly_amount' => $amount,
            'agreement_path' => null,
            'is_active' => $this->faker->boolean(),

            // El articulo 1 de la Ley 820 se aplica a vivienda urbana, asi que
            // ese es el caso por defecto. Los datos del articulo 18 se dejan a
            // proposito en null: declararlos en una factory haria que todos los
            // alquileres parecieran estar ya evaluados contra el 1 % cuando en
            // la practica casi ninguno lo esta.
            'rental_type' => RentalType::Residential->value,
            'commercial_value' => null,
            'catastral_appraisal' => null,

            // El ancla se congela en el canon con el que nace el alquiler. Se resuelve
            // como closure para que SIGA al monthly_amount cuando un test lo
            // sobreescribe con create([...]): si el ancla quedara con el valor
            // sorteado, la cadena se calcularia desde una cifra distinta de la
            // que el alquiler realmente tiene, y los tests compararian contra
            // numeros que no corresponden.
            'anchor_monthly_amount' => fn (array $attributes): float => (float) $attributes['monthly_amount'],
            'anchor_established_on' => $start->format('Y-m-d'),

            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
        ];
    }

    /**
     * Un alquiler comercial, para probar la regla del articulo 1.
     */
    public function commercial(): self
    {
        return $this->state(fn (): array => [
            'rental_type' => RentalType::Commercial->value,
        ]);
    }

    /**
     * Con los datos del articulo 18 declarados.
     */
    public function withCommercialValue(float $commercialValue, float $catastral): self
    {
        return $this->state(fn (): array => [
            'commercial_value' => $commercialValue,
            'catastral_appraisal' => $catastral,
        ]);
    }
}
