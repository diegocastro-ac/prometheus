<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 1: la Ley 820 es solo para vivienda urbana.
 *
 * "La presente ley tiene como objeto fijar los criterios que deben servir de
 * base para regular los contratos de arrendamiento de los inmuebles urbanos
 * destinados a vivienda". Un local comercial no esta sujeto a ella: se rige por
 * el Codigo de Comercio y por la Ley 1111 de 2006, donde el canon lo fija el
 * mercado y el landlord tiene mas libertad.
 *
 * Sin esta comprobacion, la aplicacion le calcularia a un local un "tope legal"
 * que ninguna norma le impone y presentaria en la carta una restriccion
 * inexistente. Ese es el tipo de error que no se ve mirando los numeros: el
 * calculo sale bien y la conclusion juridica esta mal.
 *
 * La regla es informativa a proposito. Un alquiler mal clasificado no se
 * bloquea: se avisa para que el usuario lo corrija, y mientras tanto el calculo
 * sigue igual porque no depende del tipo.
 */
class IsResidentialScope implements ReajusteRule
{
    public function name(): string
    {
        return 'is_residential_scope';
    }

    public function article(): string
    {
        return 'Art. 1, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        $rental = $context->rental;

        if ($rental->isSubjectToLey820()) {
            return RuleResult::passes($this->article());
        }

        return RuleResult::warning(
            message: __('adjustment_rules.is_residential_scope.commercial'),
            article: $this->article(),
            context: ['rental_type' => $rental->rental_type?->value],
        );
    }
}
