<?php

namespace App\Services\Adjustment\Validation;

/**
 * El incremento supera el IPC (o el canon suprime el articulo 18).
 *
 * Esta regla cambio de postura durante la construccion del modulo.
 *
 * La version anterior del sistema trataba el IPC como un tope: un incremento por
 * encima exigia acuerdo escrito y applyToRental() se negaba a aplicarlo sin esa
 * nota. La decision que se tomo al revisar fue permitirlo y avisar.
 *
 * El motivo es que no se pudo verificar la jurisprudencia al construir esto, y
 * el texto del articulo admite las dos lecturas. "Hasta en una proporcion que no
 * sea superior al 100 %" suena a limite, pero el criterio que algunos tribunales
 * han aplicado es que el IPC es un PARAMETRO de referencia y no un tope, de modo
 * que las partes pueden acordar mas. Bajo esa lectura, bloquear seria el error.
 *
 * Con lo que si se esta de acuerdo es con la consecuencia practica: la
 * comunicacion importa. El parrafo 3 del mismo articulo dice que pagar no sana la
 * falta de aviso, asi que un incremento por encima del IPC sin constancia escrita
 * es el escenario de riesgo real. De ahi que la regla avise y que la carta lo
 * destaque.
 */
class WithinCapOrAcknowledged implements ReajusteRule
{
    public function name(): string
    {
        return 'within_cap_or_acknowledged';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        if ($context->requestedRent <= $context->baseRent()) {
            // Una rebaja no pasa por el tope: baja el canon y no hay nada que
            // acotar.
            return RuleResult::passes($this->article());
        }

        if (! $context->hasIpc()) {
            return RuleResult::passes($this->article());
        }

        $cap = $context->ipc->applyTo($context->baseRent());

        if ($context->requestedRent <= $cap) {
            return RuleResult::passes($this->article());
        }

        $excess = round($context->requestedRent - $cap, 0);

        if ($context->acknowledgedDeviation) {
            return RuleResult::info(
                message: __('adjustment_rules.within_cap_or_acknowledged.acknowledged', [
                    'excess' => '$'.number_format($excess, 0, ',', '.'),
                ]),
                article: $this->article(),
                context: ['excess' => $excess, 'cap' => $cap],
            );
        }

        return RuleResult::warning(
            message: __('adjustment_rules.within_cap_or_acknowledged.warning', [
                'excess' => '$'.number_format($excess, 0, ',', '.'),
                'year' => $context->ipc->year,
            ]),
            article: $this->article(),
            context: ['excess' => $excess, 'cap' => $cap],
        );
    }
}
