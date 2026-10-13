<?php

namespace App\Infrastructure\Holidays;

use App\Contracts\BusinessCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapter (GoF).
 *
 * Envuelve el servicio publico Nager.Date, cuya interfaz es un GET HTTP que
 * responde un arreglo JSON de festivos, y la traduce a la interfaz
 * BusinessCalendar que espera el dominio.
 *
 * Este adaptador es el unico punto del sistema que conoce la forma del JSON, el
 * codigo del pais y la url del servicio. Si manana cambia la fuente de
 * festivos, cambia esta clase y nadie mas.
 *
 * La respuesta se cachea por anio para no golpear la API externa en cada
 * factura. Si la peticion falla, degrada al calendario de fin de semana en
 * lugar de romper la generacion de facturas.
 */
final class NagerDateBusinessCalendar implements BusinessCalendar
{
    private readonly WeekendBusinessCalendar $fallback;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $country,
    ) {
        $this->fallback = new WeekendBusinessCalendar();
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return ! in_array($date->toDateString(), $this->holidaysFor((int) $date->year), true);
    }

    public function nextBusinessDay(CarbonInterface $date): CarbonInterface
    {
        $candidate = Carbon::instance($date);

        // El tope cubre el peor caso de un anio cargado de festivos y evita un
        // bucle infinito si la fuente devolviera un dato corrupto.
        for ($guard = 0; $guard < 366; $guard++) {
            if ($this->isBusinessDay($candidate)) {
                return $candidate;
            }

            $candidate = $candidate->copy()->addDay();
        }

        return $this->fallback->nextBusinessDay($date);
    }

    /**
     * Festivos del anio, en formato Y-m-d, cacheados.
     *
     * @return array<int, string>
     */
    private function holidaysFor(int $year): array
    {
        $key = "holidays:{$this->country}:{$year}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $holidays = $this->fetchHolidays($year);

        // Si la API fallo, no se cachea el vacio: asi el proximo intento vuelve
        // a consultarla en lugar de arrastrar la ausencia de festivos todo el dia.
        if ($holidays === null) {
            return [];
        }

        Cache::put($key, $holidays, now()->addDay());

        return $holidays;
    }

    /**
     * @return array<int, string>|null null si la API no respondio
     */
    private function fetchHolidays(int $year): ?array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/PublicHolidays/{$year}/{$this->country}");

            if (! $response->successful()) {
                return null;
            }

            return collect($response->json())
                ->pluck('date')
                ->filter(fn (mixed $date): bool => is_string($date) && str_starts_with($date, (string) $year))
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('No se pudieron obtener los festivos de Nager.Date.', [
                'year' => $year,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
