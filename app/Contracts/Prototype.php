<?php

namespace App\Contracts;

/**
 * Prototype (GoF): el contrato no dice que contiene el objeto, solo que se
 * puede copiar. Quien lo use nunca construye uno nuevo desde cero: pide un
 * clone y trabaja con la copia.
 */
interface Prototype
{
    public function __clone(): void;
}
