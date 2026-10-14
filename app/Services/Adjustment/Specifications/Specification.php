<?php

namespace App\Services\Adjustment\Specifications;

/**
 * Una condicion booleana sobre un alquiler o un reajuste (patron Specification).
 *
 * Sirve para las preguntas que se responden con un si o un no y que aparecen en
 * mas de un sitio. La mas importante es si a este alquiler le toca reajustarse,
 * que se pregunta desde el listado, desde el panel y desde el propio formulario
 * del reajuste. Con una especificacion las tres pantallas comparten definicion y
 * no pueden discrepar.
 */
interface Specification
{
    public function isSatisfiedBy(mixed $subject): bool;
}
