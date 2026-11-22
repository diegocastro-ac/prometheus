<?php

namespace Database\Factories;

use App\Dto\Geography;
use App\Models\IpcSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpcSeries>
 */
class IpcSeriesFactory extends Factory
{
    protected $model = IpcSeries::class;

    public function definition(): array
    {
        return [
            'geography_code' => Geography::nationalCode(),
            'geography_name' => 'NACIONAL',
            'year' => 2024,
            'month' => 12,
            'index_value' => $this->faker->randomFloat(2, 100, 200),
            'variation_monthly' => $this->faker->randomFloat(2, 0, 2),
            'variation_yoy' => $this->faker->randomFloat(2, 0, 10),
            'variation_year' => $this->faker->randomFloat(2, 0, 10),
            'source_name' => 'DANE',
            'source_url' => 'https://www.dane.gov.co',
            'synced_at' => now(),
        ];
    }

    /**
     * Un dato del total nacional.
     */
    public function national(): self
    {
        return $this->state(fn (): array => [
            'geography_code' => Geography::nationalCode(),
            'geography_name' => 'NACIONAL',
        ]);
    }

    /**
     * Un dato de una ciudad concreta.
     */
    public function forCity(string $code = '20001', string $name = 'VALLEDUPAR'): self
    {
        return $this->state(fn (): array => [
            'geography_code' => $code,
            'geography_name' => $name,
        ]);
    }

    /**
     * Un periodo concreto.
     */
    public function period(int $year, int $month): self
    {
        return $this->state(fn (): array => [
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Con la variacion anual que fija el articulo 20 para ese ano.
     *
     * Los valores por defecto son los que se verificaron contra el DANE:
     * 2023 -> 9,28 %, 2024 -> 5,20 %, 2025 -> 5,10 %. Un test que los usa
     * significa lo mismo que en el mundo real, no solo que el codigo corre.
     */
    public function decemberOf(int $year, ?float $variation = null): self
    {
        $official = [
            2022 => 13.12,
            2023 => 9.28,
            2024 => 5.20,
            2025 => 5.10,
        ];

        $variation ??= $official[$year] ?? $this->faker->randomFloat(2, 2, 9);

        return $this->state(fn (): array => [
            'year' => $year,
            'month' => 12,
            'variation_year' => $variation,
            'variation_yoy' => $variation,
            'variation_monthly' => 0.3,
            'index_value' => 100 * (1 + $variation / 100),
        ]);
    }
}
