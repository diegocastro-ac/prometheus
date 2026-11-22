<?php

namespace App\Infrastructure\Holidays;

use App\Contracts\BusinessCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Implementacion de respaldo del calendario de dias habiles.
 *
 * Solo evita sabados y domingos. Se usa cuando no hay conexion, cuando la API
 * externa falla y en las pruebas, para que la generacion de facturas nunca
 * dependa de que un servicio externo este disponible.
 */
final class WeekendBusinessCalendar implements BusinessCalendar
{
    public function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend();
    }

    public function nextBusinessDay(CarbonInterface $date): CarbonInterface
    {
        $candidate = Carbon::instance($date);

        // El tope de ocho dias alcanza para cualquier puente de fin de semana y
        // evita un bucle infinito si la fecha viniera con un dato corrupto.
        for ($guard = 0; $guard < 8; $guard++) {
            if ($this->isBusinessDay($candidate)) {
                return $candidate;
            }

            $candidate = $candidate->copy()->addDay();
        }

        return $candidate;
    }
}
