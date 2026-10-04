<?php

namespace Database\Factories;

use App\Models\IpcRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpcRate>
 */
class IpcRateFactory extends Factory
{
    protected $model = IpcRate::class;

    public function definition(): array
    {
        return [
            // El año no se sortea. ipc_rates.year es único, asi que dos filas
            // sorteadas pueden repetir año y la prueba revienta con una
            // restricción que nada tiene que ver con lo que está probando.
            'year' => self::firstFreeYear(),
            'percentage' => fake()->randomFloat(2, 1.5, 15),
            'source_name' => 'DANE',
            'source_url' => 'https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/indice-de-precios-al-consumidor-ipc/ipc-historico',
            'published_on' => fake()->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),
        ];
    }

    /**
     * El primer año libre del rango.
     *
     * Determinista a proposito: los tests que necesitan un año concreto usan
     * forYear(), y los demas solo necesitan una fila valida que no choque con
     * otra creada en la misma prueba.
     */
    private static function firstFreeYear(): int
    {
        $taken = IpcRate::query()->pluck('year')->map(fn ($year): int => (int) $year)->all();

        for ($year = self::firstYear(); $year <= self::lastYear(); $year++) {
            if (! in_array($year, $taken, true)) {
                return $year;
            }
        }

        // Rango agotado. Se devuelve el primero para que la restriccion unica
        // sea la que reporte el problema, y no un bucle infinito aqui dentro.
        return self::firstYear();
    }

    private static function firstYear(): int
    {
        return 2015;
    }

    private static function lastYear(): int
    {
        return (int) now()->format('Y');
    }

    /**
     * Fija el ano y la variacion. Los documentos citan el dato, asi que los
     * tests necesitan controlar ambos para no depender de un IPC real que
     * cambia cada año.
     */
    public function forYear(int $year, float $percentage): self
    {
        return $this->state(fn (): array => [
            'year' => $year,
            'percentage' => $percentage,
        ]);
    }
}
