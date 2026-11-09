<?php

namespace App\Commands\Adjustment;

use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainRecalculator;

/**
 * Reprocesa la cadena de un alquiler (patron Command).
 *
 * El receptor es el recalculador; el comando solo fija el alquiler y expone el
 * resultado actual, que incluye el memento de la cadena.
 */
final class RecalculateRentAdjustmentChain implements AdjustmentCommand
{
    public function __construct(
        private readonly AdjustmentChainRecalculator $recalculator,
        private readonly Rental $rental,
    ) {}

    /**
     * @return array{
     *     touched: int,
     *     current_rent: float,
     *     changed_ids: list<int>,
     *     memento: \App\ValueObjects\AdjustmentChainMemento,
     * }
     */
    public function execute(): array
    {
        return $this->recalculator->recalculate($this->rental);
    }
}
