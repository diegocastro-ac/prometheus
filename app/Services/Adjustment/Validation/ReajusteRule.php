<?php

namespace App\Services\Adjustment\Validation;

/**
 * Una comprobacion sobre un reajuste en curso (patron Chain of Responsibility).
 *
 * Las reglas del articulo 18 y del 20 son independientes entre si: cada una mira
 * una cosa distinta del mismo reajuste, y todas tienen que cumplirse. La
 * cadena de responsabilidad modela eso mejor que un metodo gigante con seis if:
 * cada regla queda en su propia clase, con su articulo en la documentacion, y
 * anadir una regla nueva es agregar una clase y una linea en la composicion.
 *
 * Un detalle importante del diseno: los handlers NO bloquean. Devuelven una
 * observacion con su gravedad, y es la pantalla la que decide como la presenta.
 * La razon es que la jurisprudencia sobre si superar el 100 % del IPC esta
 * prohibido no pudo verificarse al construir esto, asi que el sistema informa y
 * deja que el arrendador decida. Bloquear con una regla cuya vigencia juridica no
 * se ha comprobado seria peor que avisar.
 */
interface ReajusteRule
{
    /**
     * El articulo de la Ley 820 que aplica, para mostrarlo en pantalla.
     */
    public function article(): string;

    /**
     * Comprueba la regla sobre el contexto.
     */
    public function check(AdjustmentContext $context): RuleResult;

    /**
     * Un nombre corto, util para logs y para los tests.
     */
    public function name(): string;
}
