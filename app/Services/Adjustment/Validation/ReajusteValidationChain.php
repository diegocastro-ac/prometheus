<?php

namespace App\Services\Adjustment\Validation;

/**
 * Corre la composicion de reglas del articulo 18 y del 20.
 *
 * La cadena se arma en AppServiceProvider con setNext(); esta clase es el punto
 * de entrada que conoce el servicio y no guarda una copia paralela del orden.
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
    public function __construct(
        private readonly ReajusteRule $head,
    ) {}

    /**
     * Todas las reglas y su resultado, en orden.
     *
     * Modo auditoria: recorre la cadena completa aunque una regla bloquee, para
     * que la pantalla no pierda avisos que el arrendador debe revisar.
     *
     * @return list<array{rule: ReajusteRule, result: RuleResult}>
     */
    public function runAll(AdjustmentContext $context): array
    {
        return $this->head->runAll($context);
    }

    /**
     * El resumen: bloqueante si algo lo es, aviso si hay avisos, si no contexto.
     *
     * Modo decision: la cadena se detiene en la primera regla bloqueante, igual
     * que hace el servicio al intentar registrar el reajuste.
     */
    public function check(AdjustmentContext $context): RuleResult
    {
        return RuleResult::summary(
            array_map(
                fn (array $entry): RuleResult => $entry['result'],
                $this->head->handle($context),
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
        return array_map(fn (ReajusteRule $rule): string => $rule->name(), $this->rules());
    }

    /**
     * Las reglas en el orden en que quedaron encadenadas.
     *
     * @return list<ReajusteRule>
     */
    public function rules(): array
    {
        $rules = [];

        for ($rule = $this->head; $rule !== null; $rule = $rule->next()) {
            $rules[] = $rule;
        }

        return $rules;
    }
}
