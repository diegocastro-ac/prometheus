<?php

namespace Database\Factories;

use App\Models\IpcRate;
use App\Models\IpcSeries;
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

    /**
     * No siembra la serie del IPC.
     *
     * Existe para los tests que necesitan la fila anual SIN que exista el dato
     * mensual. El caso real es "sin IPC registrado no se calcula el tope": ahi el
     * punto es precisamente que la serie este vacia, y sembrarla por el camino
     * corto haria que la prueba pasara por el motivo equivocado.
     */
    public function withoutSeries(): self
    {
        return $this->afterCreating(function (IpcRate $rate): void {
            // Se marca con una bandera estatica para que el hook general sepa
            // que esta fila no debe sembrar la serie.
            self::$skipsSeriesFor[] = $rate->id;
        });
    }

    /**
     * @var list<int>
     */
    private static array $skipsSeriesFor = [];

    /**
     * Sincroniza la fila anual con la serie mensual.
     *
     * Hace lo mismo que hace el sistema real, y en el mismo sentido: ipc_rates
     * es una tabla DERIVADA de ipc_series. Si un test siembra una tasa anual sin
     * su serie, el calculo del reajuste no encuentra el dato de diciembre y falla
     * por un motivo que no es el que la prueba quiere comprobar.
     *
     * Por eso sembrar la tasa siembra tambien la serie. Los tests siguen
     * escribiendo una sola linea, que es lo que un lector espera, y el estado de
     * la base de datos queda igual que en produccion.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (IpcRate $rate): void {
            if (in_array($rate->id, self::$skipsSeriesFor, true)) {
                self::$skipsSeriesFor = array_values(array_diff(self::$skipsSeriesFor, [$rate->id]));

                return;
            }

            IpcSeries::query()->updateOrCreate(
                [
                    'geography_code' => \App\Dto\Geography::nationalCode(),
                    'year' => $rate->year,
                    'month' => 12,
                ],
                [
                    'geography_name' => 'NACIONAL',
                    // Un indice coherente con la variacion. No lo usa el calculo
                    // legal, que solo necesita el porcentaje, pero la carta puede
                    // citarlo y un indice absurdo se notaria.
                    'index_value' => 100 * (1 + ($rate->percentage / 100)),
                    'variation_monthly' => 0.3,
                    'variation_yoy' => $rate->percentage,
                    'variation_year' => $rate->percentage,
                    'source_name' => $rate->source_name,
                    'source_url' => $rate->source_url,
                    'synced_at' => now(),
                ],
            );
        });
    }
}
