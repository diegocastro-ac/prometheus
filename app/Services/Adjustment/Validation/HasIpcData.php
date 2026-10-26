<?php

namespace App\Services\Adjustment\Validation;

use App\Services\Ipc\IpcSeriesRepository;

/**
 * Articulo 20: tiene que existir el IPC del ano que la ley manda.
 *
 * Es la unica regla que bloquea de verdad, y no por una razon juridica sino
 * practica: sin el dato no hay carta. Emitir una carta de reajuste que cita un
 * IPC inexistente, o emitirla sin citar ninguno, deja al arrendador sin el
 * sustento que el mismo documento dice respaldar.
 *
 * La comprobacion distingue dos ausencias que se confunden. Que no haya ningun
 * dato de diciembre para ese ano es bloqueante. Que haya datos pero sin variacion
 * anual es distinto: el indice existe y podria servir para un calculo por
 * indices, asi que el aviso dice eso en vez de Declare que no hay IPC.
 */
class HasIpcData implements ReajusteRule
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function name(): string
    {
        return 'has_ipc_data';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    public function check(AdjustmentContext $context): RuleResult
    {
        if ($context->hasIpc()) {
            return RuleResult::passes($this->article());
        }

        $statutoryYear = (int) $context->effectiveFrom->format('Y') - 1;
        $code = $context->ipc?->geographyCode ?? \App\Dto\Geography::nationalCode();

        if ($this->series->decemberOf($code, $statutoryYear) === null) {
            // El texto va en lang/ y el detalle de como traer el dato lo da
            // el servicio, no el mensaje. Un comando de consola en un texto
            // que puede leer un arrendatario no corresponde aqui.
            return RuleResult::blocking(
                message: $code === \App\Dto\Geography::nationalCode()
                    ? __('adjustment_rules.has_ipc_data.missing', [
                        'year' => $statutoryYear,
                        'next_year' => $statutoryYear + 1,
                        'effective_from' => $context->effectiveFrom->format('d/m/Y'),
                    ])
                    : __('adjustment_rules.has_ipc_data.missing_context', [
                        'year' => $statutoryYear,
                        'next_year' => $statutoryYear + 1,
                        'geography' => $context->ipc?->geographyName ?? $code,
                        'effective_from' => $context->effectiveFrom->format('d/m/Y'),
                    ]),
                article: $this->article(),
                context: ['year' => $statutoryYear, 'geography' => $code],
            );
        }

        return RuleResult::blocking(
            message: $code === \App\Dto\Geography::nationalCode()
                ? __('adjustment_rules.has_ipc_data.no_variation', ['year' => $statutoryYear])
                : __('adjustment_rules.has_ipc_data.no_variation_context', [
                    'year' => $statutoryYear,
                    'geography' => $context->ipc?->geographyName ?? $code,
                ]),
            article: $this->article(),
            context: ['year' => $statutoryYear, 'geography' => $code],
        );
    }
}
