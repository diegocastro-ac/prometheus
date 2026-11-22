<?php

namespace App\Contracts;

/**
 * Proxy (GoF) - Subject.
 *
 * Es la interfaz que comparten la fuente real de festivos y el proxy que
 * controla el acceso. El calendario pide los festivos de un anio sin saber si
 * detras hay una llamada HTTP o una respuesta cacheada.
 */
interface HolidaySource
{
    /**
     * Fechas festivas del anio, en formato Y-m-d.
     *
     * @return list<string>
     */
    public function holidaysFor(int $year): array;
}
