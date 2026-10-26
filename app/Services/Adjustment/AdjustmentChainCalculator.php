<?php

namespace App\Services\Adjustment;

use App\Enums\IpcBasis;
use App\Models\RentAdjustment;
use App\Models\Rental;
use RuntimeException;

/**
 * Reprocesa la cadena de reajustes de un alquiler desde su ancla.
 *
 * Este es el modulo que responde a una pregunta que el diseno anterior no
 * podia contestar: si se corrige un reajuste, que otros tienen que cambiar.
 *
 * El problema original era que previous_rent se copiaba del canon vigente en el
 * momento de crear el registro. Como el canon vigente es un valor que
 * applyToRental() modifica, esa copia se quedaba obsoleta en cuanto se editaba
 * un ajuste anterior, y borrar un ajuste dejaba el canon del alquiler sin
 * ninguna explicacion que lo justificara. No habia forma de recalcular porque no
 * habia ancla.
 *
 * Aqui el canon vigente no es una entrada. Se deriva:
 *
 *     R = ancla
 *     para cada ajuste, en orden:
 *         previous_rent = R
 *         legal_cap_rent = round(R * (1 + legal_pct / 100))
 *         R = new_rent
 *
 * Tres propiedades de este algoritmo:
 *
 * 1. Es determinista. Correrlo dos veces sobre los mismos insumos da el mismo
 *    resultado, asi que se puede usar como post-proceso de la sincronizacion
 *    sin miedo a mover lo que ya se comunico.
 *
 * 2. Editar un ajuste re-corre los siguientes. Si se cambia el IPC del primer
 *    ano, el canon que entra al segundo tambien cambia, porque se deriva de
 *    new_rent y no de una copia guardada.
 *
 * 3. Respeta el articulo 20. El canon que entra a cada ajuste es el canon que
 *    rigio, no el canon original del contrato. "Cada doce meses de ejecucion del
 *    contrato bajo un mismo precio": el precio vigente es el reajustado. Encadenar
 *    desde el canon original equivaldria a renunciar al 30 % del IPC del ultimo
 *    ano, y eso si seria un error.
 *
 * Lo que NO hace es multiplicar el IPC sobre el canon base. Esa confusion es
 * facil de cometer y conviene dejarla escrita: el canon base manda sobre la
 * cadena, no sobre cada incremento.
 */
class AdjustmentChainCalculator
{
    /**
     * Reprocesa la cadena completa y devuelve como quedo cada ajuste.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     *                                                                   Los cambios por ajuste: previous_rent, legal_cap_rent, anchor_rent y
     *                                                                   sequence. La columna key es el id del ajuste.
     */
    public function recalculate(Rental $rental): \Illuminate\Support\Collection
    {
        $anchor = $rental->anchorRent();

        if ($anchor === null) {
            throw new RuntimeException(sprintf(
                'El alquiler "%s" no tiene canon base con el que recalcular la cadena de '
                .'reajustes. Sin ese dato no se puede saber sobre que monto se aplico el IPC.',
                $rental->name,
            ));
        }

        $adjustments = $rental->adjustmentChain();
        $changes = collect();
        $running = $anchor;
        $sequence = 0;

        foreach ($adjustments as $adjustment) {
            $sequence++;

            $changes->put($adjustment->id, [
                'sequence' => $sequence,
                'anchor_rent' => round($running, 2),
                'previous_rent' => round($running, 2),
                'legal_cap_rent' => $this->legalCapFor($adjustment, $running),
            ]);

            // El canon que entra al siguiente es el ACORDADO, no el tope. Si las
            // partes pactaron menos que el IPC, el canon que rigio fue el
            // pactado, y encadenar sobre el tope sobreestimaria todo lo que
            // viene despues.
            $running = (float) $adjustment->new_rent;
        }

        return $changes;
    }

    /**
     * El tope legal para un ajuste, calculado sobre el canon que le precede.
     *
     * El porcentaje que se aplica es el legal, no el pactado. El tope que fija el
     * articulo 20 es el del IPC, y pactar menos no baja el tope: bajaria la
     * cifra acordada, que es otra cosa.
     */
    public function legalCapFor(RentAdjustment $adjustment, float $previousRent): float
    {
        $legalPercentage = $adjustment->legalPercentage();

        return round($previousRent * (1 + ($legalPercentage / 100)));
    }

    /**
     * El canon vigente del alquiler segun la cadena.
     *
     * Solo cuenta el ultimo ajuste APROBADO. Uno registrado pero no aplicado no
     * cambio el canon, y tomarlo como vigente haria que el alquiler mostrara un
     * precio que nunca se cobro.
     */
    public function currentRentFor(Rental $rental): float
    {
        $anchor = $rental->anchorRent() ?? (float) $rental->monthly_amount;

        $lastApplied = $rental->adjustmentChain()
            ->filter(fn (RentAdjustment $adjustment): bool => $adjustment->countsTowardsChain())
            ->last();

        return $lastApplied === null ? $anchor : (float) $lastApplied->new_rent;
    }

    /**
     * El siguiente ajuste de la cadena: el numero de secuencia siguiente.
     */
    public function nextSequence(Rental $rental): int
    {
        return ((int) $rental->rentAdjustments()->max('sequence')) + 1;
    }

    /**
     * La huella de los insumos con los que se calculo un ajuste.
     *
     * Sirve para detectar que un ajuste quedo desalineado respecto de los que se
     * derivan de el. Si dos ajustes de la misma cadena comparten ancla y el
     * anterior cambio, el segundo ya no encaja y conviene avisar en vez de
     * dejar la cadena corrupta sin que nadie lo note.
     */
    public function fingerprint(RentAdjustment $adjustment, float $previousRent): string
    {
        return substr(hash('sha256', implode('|', [
            $adjustment->id,
            $adjustment->ipc_year,
            $adjustment->legal_pct ?? $adjustment->ipc_percentage,
            number_format($previousRent, 2, '.', ''),
            $adjustment->new_rent,
            $adjustment->effective_from?->format('Y-m-d'),
        ])), 0, 64);
    }

    /**
     * La geografia que corresponde a la base elegida para este alquiler.
     *
     * Con base nacional se devuelve null, que es la senal de "usa lo que dice el
     * articulo 20". Devolver el codigo del total nacional tambien seria valido,
     * pero null deja la distincion entre "el usuario eligio el nacional" y "el
     * sistema aplico el predeterminado" sin necesidad de otro campo.
     */
    public function geographyFor(Rental $rental, IpcBasis $basis, ?string $geographyCode): ?string
    {
        if ($basis === IpcBasis::National) {
            return null;
        }

        if ($geographyCode === null || $geographyCode === '') {
            throw new RuntimeException(__('adjustment_rules.has_ipc_data.missing_city'));
        }

        return $geographyCode;
    }
}
