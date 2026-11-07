<?php

namespace App\States\Adjustment;

/**
 * Comportamiento comun de los estados de un reajuste (patron State).
 *
 * El enum AdjustmentState identifica el estado, pero no decide por si mismo que
 * se puede hacer: cada estado concreto responde que operaciones permite. Asi,
 * agregar o cambiar un estado no obliga a tocar todos los metodos del enum ni
 * las pantallas que lo consultan.
 *
 * Las transiciones NO viven aqui. El estado se sigue derivando de las fechas
 * (applied_on, notified_on) porque son las que tienen valor probatorio; estas
 * clases solo describen las capacidades de cada momento.
 */
interface AdjustmentStateBehavior
{
    /**
     * Si el reajuste puede comunicarse al arrendatario.
     */
    public function canNotify(): bool;

    /**
     * Si el canon nuevo puede aplicarse al alquiler.
     */
    public function canApply(): bool;

    /**
     * Si los datos del reajuste todavia se pueden corregir.
     */
    public function canEdit(): bool;

    /**
     * Si este reajuste forma parte de la cadena de calculo.
     */
    public function countsTowardsChain(): bool;
}
