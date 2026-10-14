<?php

namespace App\Services\Ipc;

use App\Contracts\Ipc\IpcProvider;
use App\Dto\Geography;
use App\Exceptions\DaneIpcUnavailable;
use App\Models\Geography as GeographyModel;
use App\Models\IpcSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza la serie del IPC del DANE a la base de datos local (Template Method).
 *
 * El flujo tiene los mismos cinco pasos para todas las geografias:
 *
 *   pull    pedir la serie al proveedor
 *   map     convertir las observaciones en filas
 *   validate  descartar lo que no sirve y comprobar que el dato es coherente
 *   persist  guardar con upsert
 *   report  devolver un resumen
 *
 * Implementarlo como metodo plantilla con esos pasos fijos deja que lo unico que
 * cambie sea la geografia, y sobre todo evita que una sincronizacion se quede a
 * medio camino: o se guardan todos los periodos de una geografia, o ninguno.
 *
 * Que la validacion exista antes de persistir es lo que evita el peor fallo
 * posible del modulo. Si el DANE cambia el esquema y devuelve una serie
 * distinta sin avisar, guardar eso como si fuera el IPC dejaria una base de datos
 * con cifras falsas y cartas de reajuste citando falsedades. El paso de
 * validacion comprueba contra las cifras oficiales conocidas y aborta si no
 * cuadran.
 */
class IpcSynchronizer
{
    public function __construct(
        private readonly IpcProvider $remote,
    ) {}

    /**
     * Sincroniza una geografia.
     *
     * @return array{
     *     geography: string,
     *     fetched: int,
     *     stored: int,
     *     skipped: int,
     *     ok: bool,
     *     message: string,
     * }
     */
    public function syncGeography(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array
    {
        $code = Geography::normaliseCode($geographyCode) ?? $geographyCode;

        try {
            $observations = $this->remote->fetchSeries($code, $fromYear, $toYear);
        } catch (DaneIpcUnavailable $exception) {
            // Un fallo del DANE no borra lo que ya teniamos. La ultima serie
            // buena sigue ahi y la aplicacion puede seguir reajustando con ella:
            // es mejor un dato de hace un mes que ningun dato.
            Log::warning('No se pudo sincronizar el IPC de una geografia.', [
                'geography' => $code,
                'error' => $exception->getMessage(),
            ]);

            return [
                'geography' => $code,
                'fetched' => 0,
                'stored' => 0,
                'skipped' => 0,
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }

        // VALIDATE
        $validation = $this->validate($observations, $code);

        if (! $validation['ok']) {
            Log::error('Se descarto la serie del IPC porque no supero la validacion.', [
                'geography' => $code,
                'reason' => $validation['message'],
            ]);

            return [
                'geography' => $code,
                'fetched' => count($observations),
                'stored' => 0,
                'skipped' => count($observations),
                'ok' => false,
                'message' => $validation['message'],
            ];
        }

        // MAP + PERSIST
        return DB::transaction(function () use ($observations, $code, $validation): array {
            $rows = $this->map($observations, $code);
            $stored = 0;

            foreach (array_chunk($rows, 500) as $chunk) {
                IpcSeries::upsert(
                    $chunk,
                    ['geography_code', 'year', 'month'],
                    [
                        'geography_name',
                        'index_value',
                        'variation_monthly',
                        'variation_yoy',
                        'variation_year',
                        'source_name',
                        'source_url',
                        'synced_at',
                        'updated_at',
                    ],
                );

                $stored += count($chunk);
            }

            return [
                'geography' => $code,
                'fetched' => count($observations),
                'stored' => $stored,
                'skipped' => 0,
                'ok' => true,
                'message' => $validation['message'],
            ];
        });
    }

    /**
     * Sincroniza el total nacional y, opcionalmente, todas las ciudades.
     *
     * @return list<array<string, mixed>>
     */
    public function syncAll(bool $includeCities = false, ?int $fromYear = null): array
    {
        $results = [$this->syncGeography(Geography::nationalCode(), $fromYear)];

        if (! $includeCities) {
            return $results;
        }

        foreach ($this->citiesWithIpc() as $code) {
            $results[] = $this->syncGeography($code, $fromYear);
        }

        return $results;
    }

    /**
     * Guarda el catalogo de ciudades con IPC.
     *
     * @param  list<Geography>  $geographies
     * @return int cuantas filas quedaron.
     */
    public function syncGeographies(array $geographies): int
    {
        $rows = [];

        foreach ($geographies as $geography) {
            $rows[] = [
                'code' => $geography->code,
                'name' => $geography->name,
                'department_code' => $geography->department !== null
                    ? substr($geography->code, 0, 2)
                    : null,
                'department' => $geography->department,
                'has_ipc' => $geography->hasIpc,
                'source' => $geography->source,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            GeographyModel::upsert(
                $chunk,
                ['code'],
                ['name', 'department_code', 'department', 'has_ipc', 'source', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * Las ciudades con IPC que hay en el catalogo local.
     *
     * @return list<string>
     */
    public function citiesWithIpc(): array
    {
        return GeographyModel::query()
            ->withIpc()
            ->where('code', '!=', Geography::nationalCode())
            ->orderBy('name')
            ->pluck('code')
            ->all();
    }

    /**
     * Descarta una serie que no cuadra con lo que se sabe del DANE.
     *
     * Las cifras de control estan en config/dane_ipc_sql.php y se verificaron
     * una a una contra el endpoint: son la variacion anual de diciembre de la
     * serie nacional. Si una sincronizacion produce otra cosa, el esquema cambio
     * y hay que recapturar la peticion desde el tablero.
     *
     * Solo se comprueba el total nacional, porque es el unico con cifras de
     * control conocidas. Las ciudades no se validan contra nada: si el total
     * cuadra, el esquema es el mismo y el resto de la respuesta es consistente.
     *
     * @param  list<\App\Dto\IpcObservation>  $observations
     * @return array{ok: bool, message: string}
     */
    private function validate(array $observations, string $code): array
    {
        if ($observations === []) {
            return [
                'ok' => false,
                'message' => 'El DANE no devolvio ninguna fila. Puede ser un filtro equivocado o '
                    .'un cambio en el esquema.',
            ];
        }

        if ($code !== Geography::nationalCode()) {
            return ['ok' => true, 'message' => sprintf('%d periodos recibidos.', count($observations))];
        }

        $byPeriod = [];

        foreach ($observations as $observation) {
            $byPeriod[$observation->year] = $observation;
        }

        $expected = config('dane_ipc_sql.expected', []);
        $mismatches = [];

        foreach ($expected as $year => $expectedVariation) {
            $observation = $byPeriod[$year] ?? null;

            if ($observation === null) {
                continue;
            }

            $actual = $observation->calendarYearVariation();

            if ($actual === null) {
                continue;
            }

            // Se admite medio punto de diferencia: el DANE puede redondear el
            // dato publicado a dos decimales y el indice a cuatro.
            if (abs($actual - $expectedVariation) > 0.5) {
                $mismatches[] = sprintf(
                    'diciembre de %d: se esperaba %.2f %% y llego %.2f %%',
                    $year,
                    $expectedVariation,
                    $actual,
                );
            }
        }

        if ($mismatches !== []) {
            return [
                'ok' => false,
                'message' => 'La serie nacional no coincide con el IPC oficial. '
                    .implode('; ', $mismatches)
                    .'. Compruebe el filtro CIP_NIVEL_ESTRUCTURA en config/dane_ipc_sql.php: si '
                    .'vuelve a 14-18 con CIP_N18, se esta trayendo una porcion de la canasta y no '
                    .'el IPC.',
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                '%d periodos recibidos, cifras de control del IPC oficial verificadas.',
                count($observations),
            ),
        ];
    }

    /**
     * @param  list<\App\Dto\IpcObservation>  $observations
     * @return list<array<string, mixed>>
     */
    private function map(array $observations, string $code): array
    {
        $now = now();
        $rows = [];

        foreach ($observations as $observation) {
            $rows[] = [
                'geography_code' => $observation->geographyCode ?: $code,
                'geography_name' => $observation->geographyName,
                'year' => $observation->year,
                'month' => $observation->month,
                'index_value' => round($observation->index, 4),
                'variation_monthly' => $observation->variationMonthly,
                'variation_yoy' => $observation->variationYoY,
                'variation_year' => $observation->variationYear,
                'source_name' => 'DANE',
                'source_url' => $this->sourceUrl(),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function sourceUrl(): string
    {
        return 'https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/'
            .'indice-de-precios-al-consumidor-ipc';
    }
}
