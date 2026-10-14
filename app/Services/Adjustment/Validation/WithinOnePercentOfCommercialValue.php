<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 18, parrafo 1: el canon no puede pasar del 1 % del valor comercial.
 *
 * "El precio mensual del arrendamiento sera fijado por las partes en moneda
 * legal pero no podra exceder el uno por ciento (1%) del valor comercial del
 * inmueble o de la parte de el que se de en arriendo."
 *
 * El limite es sobre el CANON COMPLETO, no sobre el incremento. Un canon de
 * 900.000 con un avaluo comercial de 80.000.000 ya esta en el limite, y un
 * reajuste legal lo deja fuera. Por eso la comprobacion compara el canon nuevo
 * contra el uno por ciento, y no el incremento.
 *
 * Cuando no hay valor comercial declarado la regla no dice nada en lugar de
 * asumir un limite. Aplicar el IPC sin conocer el valor comercial es lo normal,
 * y frenar todos los reajustes que no tengan ese dato volveria inutil el sistema.
 */
class WithinOnePercentOfCommercialValue implements ReajusteRule
{
    public function name(): string
    {
        return 'within_one_percent';
    }

    public function article(): string
    {
        return 'Art. 18, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        $rental = $context->rental;
        $cap = $rental->monthlyCapFromArticle18();

        if ($cap === null) {
            return RuleResult::passes($this->article());
        }

        if ($context->requestedRent <= $cap) {
            return RuleResult::passes($this->article());
        }

        $over = round($context->requestedRent - $cap, 0);

        return RuleResult::warning(
            message: __('adjustment_rules.within_one_percent_of_commercial_value.warning', [
                'rent' => '$'.number_format($context->requestedRent, 0, ',', '.'),
                'commercial' => '$'.number_format($cap, 0, ',', '.'),
                'excess' => '$'.number_format($over, 0, ',', '.'),
            ]),
            article: $this->article(),
            context: ['cap' => $cap, 'excess' => $over],
        );
    }
}
