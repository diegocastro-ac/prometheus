<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 20: los doce meses se cuentan bajo un mismo precio.
 *
 * El texto dice "cada doce (12) meses de ejecucion del contrato bajo un mismo
 * precio". La palabra que importa es "bajo un mismo precio": cada vez que el
 * canon cambia, el reloj se reinicia. Un alquiler reajustado en octubre de 2025
 * vuelve a cumplir doce meses en octubre de 2026, no en la fecha original del
 * contrato.
 *
 * La regla avisa en vez de bloquear. Registrar dos reajustes dentro de la misma
 * ventana es posible en la realidad: puede ser una regularizacion, un acuerdo
 * distinto al IPC o una decision comercial. Lo que no puede ser es que el
 * sistema lo permita sin que quede escrito, porque dos reajustes en doce meses
 * con el IPC del año son una de las practicas que la ley busca contener.
 */
class WithinTwelveMonthsSamePrice implements ReajusteRule
{
    public function name(): string
    {
        return 'within_twelve_months';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        $dueDate = $context->rental->nextAdjustmentDueDate();
        $effectiveFrom = $context->effectiveFrom;

        if ($effectiveFrom->greaterThanOrEqualTo($dueDate->copy()->subDay())) {
            return RuleResult::info(
                message: __('adjustment_rules.within_twelve_months.due', [
                    'due_date' => $dueDate->format('d/m/Y'),
                ]),
                article: $this->article(),
                context: ['due_date' => $dueDate->format('Y-m-d')],
            );
        }

        $days = (int) $dueDate->diffInDays($effectiveFrom, false);

        if ($context->acknowledgedDeviation) {
            return RuleResult::info(
                message: __('adjustment_rules.within_twelve_months.acknowledged_early', [
                    'due_date' => $dueDate->format('d/m/Y'),
                ]),
                article: $this->article(),
                context: ['due_date' => $dueDate->format('Y-m-d'), 'days' => $days],
            );
        }

        return RuleResult::warning(
            message: __('adjustment_rules.within_twelve_months.early', [
                'due_date' => $dueDate->format('d/m/Y'),
            ]),
            article: $this->article(),
            context: ['due_date' => $dueDate->format('Y-m-d'), 'days' => $days],
        );
    }
}
