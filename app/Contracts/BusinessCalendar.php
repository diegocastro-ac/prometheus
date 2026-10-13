<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

/**
 * Adapter (GoF) - Target.
 *
 * Es la interfaz que el dominio espera para decidir fechas de vencimiento. El
 * dominio no sabe de que fuente vienen los festivos: solo pregunta si un dia es
 * habil y, cuando no lo es, cual es el siguiente dia habil.
 *
 * El adaptador concreto (NagerDateBusinessCalendar) traduce la respuesta de un
 * servicio externo a esta interfaz. Cambiar de fuente de festivos no obliga a
 * tocar a quien consume el calendario.
 */
interface BusinessCalendar
{
    /**
     * Si la fecha es un dia en que se puede cobrar: ni fin de semana ni festivo.
     */
    public function isBusinessDay(CarbonInterface $date): bool;

    /**
     * La misma fecha si es habil, o el siguiente dia habil.
     */
    public function nextBusinessDay(CarbonInterface $date): CarbonInterface;
}
