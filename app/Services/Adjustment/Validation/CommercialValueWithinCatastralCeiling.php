<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 18, parrafo 2: el valor comercial no puede pasar de dos avaluos.
 *
 * "La estimacion comercial para efectos del presente articulo no podra exceder
 * el equivalente a dos (2) veces el avaluo catastral vigente."
 *
 * Esta segunda mitad del articulo 18 es la que casi nunca se cita y es la que
 * realmente acota a la primera. Sin ella, el uno por ciento del canon se calcula
 * sobre un valor comercial que nadie acota, y el limite legal se vuelve
 * negociable por la via de declarar un avaluo inflado.
 *
 * Se comprueba sobre los datos declarados y no sobre el canon, asi que funciona
 * como una advertencia de hygiene documental: si el valor comercial declarado no
 * tiene respaldo catastral, la carta que cites ese limite va a ser dificil de
 * sostener.
 */
class CommercialValueWithinCatastralCeiling implements ReajusteRule
{
    public function name(): string
    {
        return 'commercial_value_ceiling';
    }

    public function article(): string
    {
        return 'Art. 18, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        $rental = $context->rental;

        if ($rental->commercial_value === null || $rental->catastral_appraisal === null) {
            return RuleResult::passes($this->article());
        }

        if (! $rental->commercialValueExceedsCatastralCeiling()) {
            return RuleResult::passes($this->article());
        }

        $ceiling = $rental->commercialValueCeiling();

        return RuleResult::warning(
            message: __('adjustment_rules.commercial_value_within_catastral_ceiling.warning', [
                'commercial' => '$'.number_format((float) $rental->commercial_value, 0, ',', '.'),
                'catastral' => '$'.number_format((float) $rental->catastral_appraisal, 0, ',', '.'),
            ]),
            article: $this->article(),
            context: [
                'commercial_value' => $rental->commercial_value,
                'catastral' => $rental->catastral_appraisal,
                'ceiling' => $ceiling,
            ],
        );
    }
}
