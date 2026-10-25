<?php

namespace App\Listeners;

use App\Events\IpcSeriesSynchronized;
use App\Services\Adjustment\AdjustmentChainRecalculator;

/**
 * Rehace las tasas anuales y las cadenas de reajuste tras una sincronizacion.
 *
 * Dos trabajos encadenados, y el orden importa.
 *
 * Primero se reconstruye ipc_rates, que es la tabla que usa el codigo antiguo y
 * que la carta puede seguir leyendo. Se deriva de la serie nueva, de modo que si
 * el DANE corrige un dato, la tabla anual queda corregida tambien.
 *
 * Segundo se reprocesan las cadenas. Si no se hiciera, un alquiler que ya tiene
 * reajustes aplicados seguiria mostrando los topes calculados con la cifra
 * anterior del DANE, mientras el selector de anos ofrece la nueva. Las dos
 * pantallas darian numeros distintos para el mismo ano.
 *
 * El recargue de cadenas es idempotente: si el DANE no cambio nada, no escribe
 * ninguna fila. Por eso se puede dejar el evento siempre Connectado sin miedo a
 * que la sincronizacion mensual mueva datos que ya estaban bien.
 */
class RebuildAdjustmentChains
{
    public function __construct(
        private readonly AdjustmentChainRecalculator $recalculator,
    ) {}

    public function handle(IpcSeriesSynchronized $event): void
    {
        $this->recalculator->recalculateAll();
    }
}
