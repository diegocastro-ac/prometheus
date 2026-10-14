<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 20: el IPC es "el indice de precios al consumidor", sin ciudades.
 *
 * El articulo no distingue cobertura. El concepto 119493 del Ministerio de
 * Vivienda lo cita igual, sin agregar que pueda elegirse la ciudad del inmueble.
 * Y el DANE publica el total nacional y, ademas, una serie por ciudad: que exista
 * la segunda no significa que el articulo 20 la haya contemplado.
 *
 * Por eso usar el IPC de una ciudad es apartarse del texto legal. Es legitimo si
 * el arrendador lo decide y lo deja escrito, y por eso la regla avisa y exige
 * reconocimiento en vez de prohibitir.
 *
 * Lo que no se permite es presentarlo como si fuera el tope legal. El snapshot
 * llega marcado como no legal justamente para que la carta no pueda
 * confundirlos.
 */
class CityIpcRequiresAcknowledgement implements ReajusteRule
{
    public function name(): string
    {
        return 'city_ipc_acknowledgement';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        if (! $context->hasIpc() || $context->ipc->isStatutory) {
            return RuleResult::passes($this->article());
        }

        $geography = $context->ipc->geographyName;
        $national = $this->nationalPercentage($context);

        $comparison = $national === null
            ? ''
            : __('adjustment_rules.city_ipc_requires_acknowledgement.national_reference', [
                'pct' => number_format($national, 2, ',', '.'),
            ]);

        if ($context->acknowledgedDeviation) {
            return RuleResult::info(
                message: __('adjustment_rules.city_ipc_requires_acknowledgement.acknowledged', [
                    'geography' => $geography,
                ]),
                article: $this->article(),
                context: ['geography' => $context->ipc->geographyCode],
            );
        }

        return RuleResult::warning(
            message: __('adjustment_rules.city_ipc_requires_acknowledgement.warning', [
                'geography' => $geography,
                'national_reference' => $comparison,
            ]),
            article: $this->article(),
            context: ['geography' => $context->ipc->geographyCode],
        );
    }

    /**
     * El IPC del total nacional para el mismo ano, para poder comparar.
     *
     * Devuelve null cuando no hay dato nacional, en lugar de fallar: la regla
     * trata de la base elegida, y la ausencia del dato nacional ya la revisa
     * HasIpcData con un mensaje propio.
     */
    private function nationalPercentage(AdjustmentContext $context): ?float
    {
        $row = app(\App\Services\Ipc\IpcSeriesRepository::class)
            ->decemberOf(\App\Dto\Geography::nationalCode(), $context->ipc->year);

        return $row?->calendarYearVariation();
    }
}
