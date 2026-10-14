<?php

namespace App\Listeners;

use App\Events\IpcSeriesSynchronized;
use App\Models\IpcRate;
use App\Services\Ipc\IpcSeriesRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconstruye la tabla de tasas anuales desde la serie.
 *
 * ipc_rates existia como tabla con una fila por ano, con el porcentaje del IPC
 * escrito a mano. Eso era un problema por tres razones:
 *
 * 1. Los datos se desactualizaban. Un año nuevo no aparecia hasta que alguien lo
 *    escribia.
 *
 * 2. Se desfasaron. El seeder guardaba 2025 con 9,28 %, que es en realidad el IPC
 *    de 2023. Como la tabla es la que usa el calculo, un reajuste de 2026 se
 *    calculaba con la cifra de 2024. Un error de un año entero en el tope legal de
 *    todo el sistema.
 *
 * 3. No se podia auditar. Decir "el IPC de 2025 fue 9,28" sin más no permite
 *    comprobar de donde sale.
 *
 * Ahora la tabla es derivada: sale de ipc_series, que es la copia fiel del DANE.
 * La migracion de datos se hizo tomando diciembre de cada año, que es exactamente
 * lo que el articulo 20 ordena tomar.
 */
class RebuildAnnualRates
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function handle(IpcSeriesSynchronized $event): void
    {
        $code = \App\Dto\Geography::nationalCode();

        $observations = \App\Models\IpcSeries::query()
            ->forGeography($code)
            ->withDecember()
            ->whereNotNull('variation_year')
            ->orderBy('year')
            ->get();

        if ($observations->isEmpty()) {
            Log::warning('Se omite la reconstruccion de ipc_rates: no hay datos de diciembre.');

            return;
        }

        DB::transaction(function () use ($observations): void {
            foreach ($observations as $observation) {
                $variation = $observation->calendarYearVariation();

                if ($variation === null) {
                    continue;
                }

                // updateOrCreate y no firstOrCreate: corregir el dato de un año no
                // puede exigir borrar la fila, que ya puede tener ajustes y cartas
                // de reajuste que la referencian.
                IpcRate::updateOrCreate(
                    ['year' => $observation->year],
                    [
                        'percentage' => round($variation, 2),
                        'source_name' => $observation->source_name ?? 'DANE',
                        'source_url' => $observation->source_url,
                        'published_on' => $observation->updated_at?->toDateString(),
                    ],
                );
            }
        });
    }
}
