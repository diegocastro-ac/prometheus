<?php

namespace App\Services\Adjustment;

use App\Models\RentAdjustment;
use App\Models\Rental;
use App\ValueObjects\AdjustmentChainMemento;
use App\ValueObjects\AdjustmentMemento;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta y persiste el reproceso de la cadena.
 *
 * El calculator dice que tiene que pasar; esta clase lo escribe. La separacion
 * es deliberada: el algoritmo puro se puede probar sin base de datos, y aqui se
 * concentran las decisiones que dependen del almacenamiento, como que una
 * transacion abarque toda la cadena para que un fallo a mitad no deje ajustes a
 * medias.
 *
 * Es tambien el cuidador (Caretaker) del patron Memento: antes de escribir
 * captura la cadena, devuelve esa fotografia al llamador y puede restaurarla.
 * Asi el reproceso deja de ser irreversible sin necesidad de una tabla de
 * historial.
 *
 * Cuando algo cambia, se actualizan todos los ajustes, no solo el que se toco.
 * Es la consequence logica de que previous_rent sea derivado: si el canon de
 * entrada del ajuste 3 no cambio, su registro no se toca, y se evita reescribir
 * filas que ya estan bien.
 */
class AdjustmentChainRecalculator
{
    public function __construct(
        private readonly AdjustmentChainCalculator $calculator,
    ) {}

    /**
     * Reprocesa la cadena del alquiler y guarda lo que cambio.
     *
     * @return array{
     *     touched: int,
     *     current_rent: float,
     *     changed_ids: list<int>,
     *     memento: AdjustmentChainMemento,
     * }
     */
    public function recalculate(Rental $rental, bool $syncRentalRent = true): array
    {
        return DB::transaction(function () use ($rental, $syncRentalRent): array {
            $rental->refresh();

            // El memento se captura antes de escribir: es la unica forma de
            // devolver la cadena a su estado anterior si el resultado no era el
            // esperado.
            $memento = $rental->createAdjustmentChainMemento();

            $changes = $this->calculator->recalculate($rental);
            $changedIds = [];

            foreach ($changes as $adjustmentId => $change) {
                $adjustment = RentAdjustment::query()->find($adjustmentId);

                if ($adjustment === null) {
                    continue;
                }

                $updates = [
                    'sequence' => $change['sequence'],
                    'anchor_rent' => $change['anchor_rent'],
                    'previous_rent' => $change['previous_rent'],
                    'legal_cap_rent' => $change['legal_cap_rent'],
                    'excess_over_cap' => $change['excess_over_cap'],
                ];

                // previous_rent pasa a ser derivado, asi que puede quedar
                // desfasado si el canon acordado cambio. El excess tambien.
                if ($this->differs($adjustment, $updates)) {
                    $adjustment->forceFill($updates)->saveQuietly();
                    $changedIds[] = (int) $adjustment->id;
                }
            }

            $currentRent = $this->calculator->currentRentFor($rental->refresh());

            if ($syncRentalRent && (float) $rental->monthly_amount !== $currentRent) {
                $rental->forceFill(['monthly_amount' => $currentRent])->saveQuietly();
            }

            return [
                'touched' => count($changedIds),
                'current_rent' => $currentRent,
                'changed_ids' => $changedIds,
                'memento' => $memento,
            ];
        });
    }

    /**
     * Calcula lo que cambiaria sin escribir nada.
     *
     * El memento es el "antes"; los cambios del calculator son el "despues".
     * Solo se devuelven los ajustes que realmente se moverian, para que la
     * simulacion no repita la cadena entera cuando no hay nada que corregir.
     *
     * @return array{
     *     touched: int,
     *     current_rent: float,
     *     changes: list<array{adjustment_id: int, before: AdjustmentMemento, after: array<string, mixed>}>,
     *     memento: AdjustmentChainMemento,
     * }
     */
    public function preview(Rental $rental): array
    {
        $rental->refresh();

        $memento = $rental->createAdjustmentChainMemento();
        $changes = $this->calculator->recalculate($rental);
        $diffs = [];

        foreach ($changes as $adjustmentId => $change) {
            $before = $memento->adjustment((int) $adjustmentId);

            if ($before === null || ! $this->mementoDiffers($before, $change)) {
                continue;
            }

            $diffs[] = [
                'adjustment_id' => (int) $adjustmentId,
                'before' => $before,
                'after' => $change,
            ];
        }

        return [
            'touched' => count($diffs),
            'current_rent' => $this->calculator->currentRentFor($rental),
            'changes' => $diffs,
            'memento' => $memento,
        ];
    }

    /**
     * Simula el reproceso de todos los alquileres con reajustes.
     *
     * @param  callable(Rental, array): void|null  $onEach  para reportar avance.
     * @return array{rentals: int, touched: int, changed_rentals: int}
     */
    public function previewAll(?callable $onEach = null): array
    {
        $rentals = 0;
        $touched = 0;
        $changedRentals = 0;

        Rental::query()
            ->whereNotNull('anchor_monthly_amount')
            ->whereHas('rentAdjustments')
            ->chunkById(100, function ($chunk) use (&$rentals, &$touched, &$changedRentals, $onEach): void {
                foreach ($chunk as $rental) {
                    $preview = $this->preview($rental);

                    $rentals++;
                    $touched += $preview['touched'];

                    if ($preview['touched'] > 0) {
                        $changedRentals++;
                    }

                    if ($onEach !== null) {
                        $onEach($rental, $preview);
                    }
                }
            });

        return [
            'rentals' => $rentals,
            'touched' => $touched,
            'changed_rentals' => $changedRentals,
        ];
    }

    /**
     * Devuelve la cadena al estado que guarda el memento.
     *
     * Todo ocurre en una transaccion: el canon del alquiler y sus reajustes se
     * restauran juntos o no se restaura nada.
     */
    public function restore(AdjustmentChainMemento $memento): bool
    {
        return DB::transaction(function () use ($memento): bool {
            $rental = Rental::query()->find($memento->rentalId);

            if ($rental === null) {
                return false;
            }

            $rental->restoreAdjustmentChain($memento);

            return true;
        });
    }

    /**
     * Reprocesa todos los alquileres que tengan reajustes.
     *
     * Se usa como post-proceso de la sincronizacion del IPC y como
     * mantenimiento. Es idempotente: si no cambio nada, la segunda corrida no
     * escribe ni una fila.
     *
     * @param  callable(Rental): void|null  $onEach  para reportar avance.
     * @return array{rentals: int, touched: int}
     */
    public function recalculateAll(?callable $onEach = null): array
    {
        $touched = 0;
        $rentals = 0;

        Rental::query()
            ->whereNotNull('anchor_monthly_amount')
            ->whereHas('rentAdjustments')
            ->chunkById(100, function ($chunk) use (&$touched, &$rentals, $onEach): void {
                foreach ($chunk as $rental) {
                    $result = $this->recalculate($rental);

                    $rentals++;
                    $touched += $result['touched'];

                    if ($onEach !== null) {
                        $onEach($rental);
                    }
                }
            });

        return ['rentals' => $rentals, 'touched' => $touched];
    }

    /**
     * Compara el memento de un ajuste con los valores que produciria el
     * recalculo.
     *
     * @param  array<string, mixed>  $change
     */
    private function mementoDiffers(AdjustmentMemento $before, array $change): bool
    {
        if ($before->sequence !== (int) $change['sequence']) {
            return true;
        }

        if (round($before->anchorRent, 2) !== round((float) $change['anchor_rent'], 2)) {
            return true;
        }

        if (round($before->previousRent, 2) !== round((float) $change['previous_rent'], 2)) {
            return true;
        }

        if (round($before->legalCapRent, 2) !== round((float) $change['legal_cap_rent'], 2)) {
            return true;
        }

        return round($before->excessOverCap, 0) !== round((float) $change['excess_over_cap'], 0);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function differs(RentAdjustment $adjustment, array $updates): bool
    {
        if ((int) $adjustment->sequence !== (int) $updates['sequence']) {
            return true;
        }

        if (round((float) $adjustment->anchor_rent, 2) !== round((float) $updates['anchor_rent'], 2)) {
            return true;
        }

        if (round((float) $adjustment->previous_rent, 2) !== round((float) $updates['previous_rent'], 2)) {
            return true;
        }

        if (round((float) $adjustment->legal_cap_rent, 2) !== round((float) $updates['legal_cap_rent'], 2)) {
            return true;
        }

        return round((float) $adjustment->excess_over_cap, 0) !== round((float) $updates['excess_over_cap'], 0);
    }
}
