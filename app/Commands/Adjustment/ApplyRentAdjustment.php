<?php

namespace App\Commands\Adjustment;

use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use RuntimeException;

/**
 * Aplica el canon reajustado al alquiler (patron Command).
 *
 * El receptor es el recalculador: aplicar no es solo marcar applied_on, es
 * reprocesar la cadena para que el canon vigente y los topes queden coherentes.
 */
final class ApplyRentAdjustment implements AdjustmentCommand
{
    public function __construct(
        private readonly AdjustmentChainRecalculator $recalculator,
        private readonly RentAdjustment $adjustment,
    ) {}

    public function execute(): Rental
    {
        $rental = $this->adjustment->rental;

        if ($this->adjustment->effective_from === null) {
            throw new RuntimeException(
                'El reajuste no tiene fecha de vigencia. Sin ella no se puede aplicar al canon.'
            );
        }

        $this->adjustment->update(['applied_on' => now()->toDateString()]);

        $this->recalculator->recalculate($rental);

        return $rental->refresh();
    }
}
