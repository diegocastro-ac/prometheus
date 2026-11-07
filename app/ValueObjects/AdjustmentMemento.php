<?php

namespace App\ValueObjects;

/**
 * Fotografia inmutable del estado que el recalculo puede pisar en un reajuste
 * (patron Memento).
 *
 * Solo guarda los campos derivados que escribe AdjustmentChainRecalculator.
 * No contiene el modelo ni acceso a la base de datos: el cuidador puede
 * devolver estos valores sin abrir el reajuste ni exponer el resto de sus
 * atributos.
 */
final readonly class AdjustmentMemento
{
    public function __construct(
        public int $adjustmentId,
        public int $sequence,
        public float $anchorRent,
        public float $previousRent,
        public float $legalCapRent,
        public float $excessOverCap,
    ) {}
}
