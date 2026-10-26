<?php

namespace App\Infrastructure\Holidays;

use App\Contracts\BusinessCalendar;
use App\Contracts\HolidaySource;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Implementacion del calendario de dias habiles que consulta una fuente de
 * festivos.
 *
 * Es el Client del Proxy: no conoce la URL, el JSON ni la cache. Solo pregunta
 * por los festivos del anio a la interfaz HolidaySource. Si la fuente no puede
 * responder, el Proxy devuelve una lista vacia y este calendario sigue
 * saltando fines de semana.
 */
final class NagerDateBusinessCalendar implements BusinessCalendar
{
    private readonly WeekendBusinessCalendar $fallback;

    public function __construct(private readonly HolidaySource $source)
    {
        $this->fallback = new WeekendBusinessCalendar;
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return ! in_array($date->toDateString(), $this->source->holidaysFor((int) $date->year), true);
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
}
