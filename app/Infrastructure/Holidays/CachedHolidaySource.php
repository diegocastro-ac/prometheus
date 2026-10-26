<?php

namespace App\Infrastructure\Holidays;

use App\Contracts\HolidaySource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proxy (GoF).
 *
 * Controla el acceso a la fuente real: responde desde cache cuando el anio ya
 * fue consultado y, si la fuente falla, registra el problema y devuelve una
 * lista vacia para degradar al calendario de fin de semana.
 *
 * El fallo no se cachea. Cachear un vacio haria creer al sistema que no hay
 * festivos durante todo el dia; sin cache, el siguiente intento vuelve a
 * consultar la API.
 */
final class CachedHolidaySource implements HolidaySource
{
    public function __construct(
        private readonly HolidaySource $real,
        private readonly string $country,
    ) {}

    public function holidaysFor(int $year): array
    {
        $key = "holidays:{$this->country}:{$year}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $holidays = $this->real->holidaysFor($year);
        } catch (Throwable $exception) {
            Log::warning('No se pudieron obtener los festivos de Nager.Date.', [
                'year' => $year,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        Cache::put($key, $holidays, now()->addDay());

        return $holidays;
    }
}
