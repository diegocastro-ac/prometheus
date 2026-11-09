<?php

namespace App\States\Adjustment;

/**
 * Reajuste comunicado: la fecha y el medio ya estan registrados.
 *
 * A partir de aqui el incremento es oponible, por eso ya no se vuelve a
 * notificar. Todavia no cambio el canon del alquiler: la aplicacion es un paso
 * distinto. Se puede corregir mientras no se aplique.
 */
final class NotifiedState implements AdjustmentStateBehavior
{
    public function canNotify(): bool
    {
        return false;
    }

    public function canApply(): bool
    {
        return true;
    }

    public function canEdit(): bool
    {
        return true;
    }

    public function countsTowardsChain(): bool
    {
        return false;
    }
}
