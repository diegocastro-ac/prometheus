<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 20: el IPC es el del ano calendario inmediatamente anterior.
 *
 * El articulo no dice "el IPC mas reciente disponible". Dice "el incremento que
 * haya tenido el indice de precios al consumidor en el ano calendario
 * inmediatamente anterior a aquel en que deba efectuarse el reajuste". Para un
 * reajuste que rige en 2026, eso es el IPC de 2025, y no el de 2026 aunque ya
 * este publicado.
 *
 * Confundir las dos cosas es facil y produce un resultado que parece correcto:
 * el formulario ofrece el ultimo IPC disponible, que suele ser el del ano en
 * curso, y el usuario lo acepta sin notar que la ley pedia otro.
 *
 * Esta regla avisa en vez de impedir. La jurisprudencia que se dejo sin resolver
 * al construir esto indica que el IPC se lee como un parametro de referencia y
 * no como un tope, de modo que apartarse es legitimo si el arrendador lo asume
 * por escrito. Lo que no es admisible es apartarse en silencio.
 */
class StatutoryCalendarYear implements ReajusteRule
{
    public function name(): string
    {
        return 'statutory_calendar_year';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        if (! $context->hasIpc()) {
            // Lo comprueba HasIpcData con un mensaje mas preciso.
            return RuleResult::passes($this->article());
        }

        $statutoryYear = (int) $context->effectiveFrom->format('Y') - 1;
        $appliedYear = $context->ipc->year;

        if ($appliedYear === $statutoryYear) {
            return RuleResult::passes($this->article());
        }

        if ($context->acknowledgedDeviation) {
            return RuleResult::info(
                message: __('adjustment_rules.statutory_calendar_year.acknowledged', [
                    'chosen' => $appliedYear,
                    'statutory' => $statutoryYear,
                ]),
                article: $this->article(),
                context: ['applied' => $appliedYear, 'statutory' => $statutoryYear],
            );
        }

        return RuleResult::warning(
            message: __('adjustment_rules.statutory_calendar_year.mismatch', [
                'statutory' => $statutoryYear,
                'effective_from' => $context->effectiveFrom->format('d/m/Y'),
                'chosen' => $appliedYear,
            ]),
            article: $this->article(),
            context: ['applied' => $appliedYear, 'statutory' => $statutoryYear],
        );
    }
}
