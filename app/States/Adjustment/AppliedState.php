<?php

namespace App\States\Adjustment;

/**
 * Reajuste aplicado: el canon nuevo ya rige en el alquiler.
 *
 * Es el unico estado que cuenta para la cadena, porque es el unico que cambio el
 * precio sobre el que se calcula el siguiente reajuste. Ya no se notifica, no se
 * vuelve a aplicar y tampoco se edita: corregirlo obligaria a reprocesar la
 * cadena completa.
 */
final class AppliedState implements AdjustmentStateBehavior
{
    public function canNotify(): bool
    {
        return false;
    }

    public function canApply(): bool
    {
        return false;
    }

    public function canEdit(): bool
    {
        return false;
    }

    public function countsTowardsChain(): bool
    {
        return true;
    }
}
