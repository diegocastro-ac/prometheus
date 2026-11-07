<?php

namespace App\States\Adjustment;

/**
 * Reajuste registrado: existe, pero todavia no se comunico al arrendatario.
 *
 * Es el unico estado desde el que se puede notificar. El canon del alquiler no
 * cambio, asi que no cuenta para la cadena del siguiente reajuste. Se puede
 * editar mientras no se aplique.
 */
final class RecordedState implements AdjustmentStateBehavior
{
    public function canNotify(): bool
    {
        return true;
    }

    public function canApply(): bool
    {
        return false;
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
