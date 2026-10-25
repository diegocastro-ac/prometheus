<?php

namespace App\Services\Adjustment;

use App\Models\RentAdjustment;
use App\Models\Rental;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta y persiste el reproceso de la cadena (patron Command).
 *
 * El calculator dice que tiene que pasar; esta clase lo escribe. La separacion
 * es deliberada: el algoritmo puro se puede probar sin base de datos, y aqui se
 * concentran las decisiones que dependen del almacenamiento, como que una
 * transacion abarque toda la cadena para que un fallo a mitad no deje ajustes a
 * medias.
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
     * }
     */
    public function recalculate(Rental $rental, bool $syncRentalRent = true): array
    {
        return DB::transaction(function () use ($rental, $syncRentalRent): array {
            $rental->refresh();

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
                    'excess_over_cap' => $this->excessFor((float) $adjustment->new_rent, (float) $change['legal_cap_rent']),
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
            ];
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
     * Que parte del canon pedido se sale del tope legal.
     *
     * Se recalcula en cada pasada porque depende del canon de entrada. Antes
     * quedaba congelado con el ajuste y se voltaba obsoleto igual que
     * previous_rent.
     */
    private function excessFor(float $newRent, float $legalCap): float
    {
        if ($newRent <= $legalCap) {
            return 0.0;
        }

        return round($newRent - $legalCap, 0);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function differs(RentAdjustment $adjustment, array $updates): bool
    {
        if ((int) $adjustment->sequence !== $updates['sequence']) {
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
