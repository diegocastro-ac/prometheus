<?php

namespace App\Services\Adjustment\Validation;

/**
 * Una comprobacion sobre un reajuste en curso (patron Chain of Responsibility).
 *
 * Las reglas del articulo 18 y del 20 son independientes entre si: cada una mira
 * una cosa distinta del mismo reajuste, y todas tienen que cumplirse. La cadena
 * de responsabilidad modela eso mejor que un metodo gigante con seis if: cada
 * regla queda en su propia clase, con su articulo en la documentacion, y anadir
 * una regla nueva es agregar una clase y encadenarla en el provider.
 *
 * La base aporta el enlace (next/setNext) y dos recorridos:
 *
 * - handle(): modo decision. Se detiene en la primera regla bloqueante porque
 *   las siguientes asumen que el reajuste tiene los datos minimos para existir.
 * - runAll(): modo auditoria. Recorre toda la cadena aunque haya un bloqueo,
 *   porque el panel de comprobaciones necesita mostrar todos los avisos.
 *
 * Un detalle importante del diseno: los handlers NO lanzan excepciones ni
 * escriben. Devuelven una observacion con su gravedad; es el cliente
 * (RentAdjustmentService) quien decide si un bloqueo impide guardar. La razon es
 * que la jurisprudencia sobre si superar el 100 % del IPC esta prohibido no pudo
 * verificarse al construir esto, asi que el sistema informa y deja que el
 * arrendador decida. Bloquear con una regla cuya vigencia juridica no se ha
 * comprobado seria peor que avisar; por eso solo bloquea la ausencia de un dato
 * imprescindible, como el IPC.
 */
abstract class ReajusteRule
{
    private ?ReajusteRule $next = null;

    /**
     * Encadena la siguiente regla y la devuelve para permitir composicion fluida.
     */
    public function setNext(ReajusteRule $next): ReajusteRule
    {
        $this->next = $next;

        return $next;
    }

    public function next(): ?ReajusteRule
    {
        return $this->next;
    }

    /**
     * Recorre la cadena en modo decision.
     *
     * Si una regla devuelve un resultado bloqueante, la cadena se corta: las
     * reglas posteriores dependen de que el reajuste tenga los datos minimos y
     * seguir ejecutandolas solo produciria ruido. Los avisos no cortan.
     *
     * @return list<array{rule: ReajusteRule, result: RuleResult}>
     */
    final public function handle(AdjustmentContext $context): array
    {
        return $this->collect($context, stopOnBlocking: true);
    }

    /**
     * Recorre la cadena completa en modo auditoria.
     *
     * No se detiene ante un bloqueo: la pantalla necesita el detalle de todas
     * las comprobaciones, incluidas las que quedarian fuera de la decision.
     *
     * @return list<array{rule: ReajusteRule, result: RuleResult}>
     */
    final public function runAll(AdjustmentContext $context): array
    {
        return $this->collect($context, stopOnBlocking: false);
    }

    /**
     * @return list<array{rule: ReajusteRule, result: RuleResult}>
     */
    private function collect(AdjustmentContext $context, bool $stopOnBlocking): array
    {
        $result = $this->check($context);
        $entries = [['rule' => $this, 'result' => $result]];

        if ($this->next === null || ($stopOnBlocking && $result->isBlocking())) {
            return $entries;
        }

        return array_merge($entries, $this->next->collect($context, $stopOnBlocking));
    }

    /**
     * El articulo de la Ley 820 que aplica, para mostrarlo en pantalla.
     */
    abstract public function article(): string;

    /**
     * Comprueba la regla sobre el contexto.
     */
    abstract public function check(AdjustmentContext $context): RuleResult;

    /**
     * Un nombre corto, util para logs y para los tests.
     */
    abstract public function name(): string;
}
