<?php

namespace App\Services\Adjustment\Validation;

/**
 * Corre la composicion de reglas del articulo 18 y del 20.
 *
 * El orden importa, y no por performance sino por la calidad de los mensajes. Las
 * reglas estructurales van primero: si no hay IPC o el alquiler no cumple los
 * doce meses, avisar del uno por ciento del articulo 18 es ruido que tapa el
 * problema de fondo. Las reglas de importe van al final, cuando ya se sabe que
 * hay un reajuste en pie.
 *
 * La cadena devuelve el detalle completo, no solo el resumen, porque la pantalla
 * lo necesita: cada aviso lleva el articulo que lo sustenta y asi el arrendador
 * puede ir a leer la norma en vez de creerle al sistema.
 */
class ReajusteValidationChain
{
    /**
     * @var list<ReajusteRule>
     */
    private array $rules;

    /**
     * @param  list<ReajusteRule>|null  $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? [
            new HasIpcData(app(\App\Services\Ipc\IpcSeriesRepository::class)),
            new IsResidentialScope,
            new WithinTwelveMonthsSamePrice,
            new StatutoryCalendarYear,
            new CityIpcRequiresAcknowledgement,
            new CommercialValueWithinCatastralCeiling,
            new WithinOnePercentOfCommercialValue,
            new WithinCapOrAcknowledged,
            new NotificationHasValidMeans,
        ];
    }

    /**
     * Todas las reglas y su resultado, en orden.
     *
     * @return list<array{rule: ReajusteRule, result: RuleResult}>
     */
    public function runAll(AdjustmentContext $context): array
    {
        $output = [];

        foreach ($this->rules as $rule) {
            $output[] = ['rule' => $rule, 'result' => $rule->check($context)];
        }

        return $output;
    }

    /**
     * El resumen: bloqueante si algo lo es, aviso si hay avisos, si no contexto.
     */
    public function check(AdjustmentContext $context): RuleResult
    {
        return RuleResult::summary(
            array_map(
                fn (array $entry): RuleResult => $entry['result'],
                $this->runAll($context),
            ),
        );
    }

    /**
     * Los mensajes que hay que mostrar, ya en texto.
     *
     * @return list<array{severity: string, message: string, article: ?string}>
     */
    public function messages(AdjustmentContext $context): array
    {
        return array_values(array_map(
            fn (array $entry): array => [
                'severity' => $entry['result']->severity,
                'message' => $entry['result']->message,
                'article' => $entry['result']->article,
            ],
            array_filter(
                $this->runAll($context),
                fn (array $entry): bool => $entry['result']->hasMessage(),
            ),
        ));
    }

    /**
     * Los nombres de las reglas activas, para los tests y los logs.
     *
     * @return list<string>
     */
    public function ruleNames(): array
    {
        return array_map(fn (ReajusteRule $rule): string => $rule->name(), $this->rules);
    }

    /**
     * @return list<ReajusteRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
