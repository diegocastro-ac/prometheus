<?php

namespace App\ValueObjects;

/**
 * Fotografia inmutable de la cadena de reajustes de un alquiler (patron
 * Memento).
 *
 * Guarda el canon vigente y el memento de cada reajuste, indexado por id. Es lo
 * que permite devolver la cadena completa a su estado anterior sin crear una
 * tabla de historial ni duplicar los campos en otro sitio.
 */
final readonly class AdjustmentChainMemento
{
    /**
     * @param  array<int, AdjustmentMemento>  $adjustments  indexado por id del reajuste.
     */
    public function __construct(
        public int $rentalId,
        public float $monthlyAmount,
        public array $adjustments,
    ) {}

    public function adjustment(int $adjustmentId): ?AdjustmentMemento
    {
        return $this->adjustments[$adjustmentId] ?? null;
    }

    public function count(): int
    {
        return count($this->adjustments);
    }
}
