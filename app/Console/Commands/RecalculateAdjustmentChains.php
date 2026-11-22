<?php

namespace App\Console\Commands;

use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use Illuminate\Console\Command;

/**
 * Reprocesa las cadenas de reajuste de los alquileres.
 *
 * Es el comando de mantenimiento del calculo. Se necesita despues de editar o de
 * borrar un reajuste, y tambien cuando el DANE corrige un dato: en ese caso los
 * topes que se calcularon con la cifra vieja quedan desalineados de la copia
 * local, y sin reprocesarlos las dos pantallas darian numeros distintos para el
 * mismo ano.
 *
 * Es idempotente. Correrlo dos veces no escribe nada la segunda, asi que se puede
 * dejar programado sin miedo a que mueva datos que ya estaban correctos.
 */
class RecalculateAdjustmentChains extends Command
{
    protected $signature = 'rent-adjustments:recalculate
                            {--rental= : Limita el recalculo a un alquiler concreto}
                            {--dry-run : Muestra lo que cambiaria sin escribir nada}';

    protected $description = 'Reprocesa la cadena de reajustes de los alquileres desde su canon base';

    public function handle(AdjustmentChainRecalculator $recalculator, AdjustmentChainCalculator $calculator): int
    {
        if ($this->option('rental')) {
            return $this->recalculateOne((int) $this->option('rental'), $recalculator, $calculator);
        }

        $rentals = Rental::query()
            ->whereNotNull('anchor_monthly_amount')
            ->whereHas('rentAdjustments')
            ->count();

        if ($rentals === 0) {
            $this->info('No hay alquileres con reajustes registrados.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Reprocesando %d alquileres con reajustes...', $rentals));

        $touched = 0;
        $changed = 0;

        Rental::query()
            ->whereNotNull('anchor_monthly_amount')
            ->whereHas('rentAdjustments')
            ->chunkById(50, function ($chunk) use (&$touched, &$changed, $recalculator): void {
                foreach ($chunk as $rental) {
                    $result = $recalculator->recalculate($rental);

                    $touched += $result['touched'];
                    $changed++;
                }

                $this->output->write('.');
            });

        $this->newLine();

        $this->info(sprintf(
            '%d alquileres revisados, %d reajustes actualizados.',
            $changed,
            $touched,
        ));

        if ($touched === 0) {
            $this->line('  Nada cambio: las cadenas ya estaban coherentes con sus anclas.');
        }

        return self::SUCCESS;
    }

    private function recalculateOne(
        int $rentalId,
        AdjustmentChainRecalculator $recalculator,
        AdjustmentChainCalculator $calculator,
    ): int {
        $rental = Rental::query()->find($rentalId);

        if ($rental === null) {
            $this->error("No existe el alquiler {$rentalId}.");

            return self::FAILURE;
        }

        $anchor = $rental->anchorRent();

        if ($anchor === null) {
            $this->error(sprintf(
                'El alquiler "%s" no tiene canon base. Sin el no se puede reprocesar la cadena.',
                $rental->name,
            ));

            return self::FAILURE;
        }

        $this->line(sprintf('Alquiler: %s', $rental->name));
        $this->line(sprintf('Canon base: $%s', number_format($anchor, 0, ',', '.')));

        if ($this->option('dry-run')) {
            $this->preview($rental, $calculator);

            return self::SUCCESS;
        }

        $result = $recalculator->recalculate($rental);

        $this->line(sprintf(
            '%d reajustes actualizados. Canon vigente del alquiler: $%s',
            $result['touched'],
            number_format($result['current_rent'], 0, ',', '.'),
        ));

        return self::SUCCESS;
    }

    private function preview(Rental $rental, AdjustmentChainCalculator $calculator): void
    {
        $this->newLine();
        $this->line('Estado actual de la cadena:');

        $running = $rental->anchorRent();

        foreach ($rental->adjustmentChain() as $adjustment) {
            $this->line(sprintf(
                '  #%d  %s  IPC %d (%.2f %%)  canon %s -> %s  [estado: %s]',
                $adjustment->sequence ?? 0,
                $adjustment->effective_from?->format('Y-m-d') ?? 'sin fecha',
                $adjustment->ipc_year ?? 0,
                $adjustment->legalPercentage(),
                number_format((float) $adjustment->previous_rent, 0, ',', '.'),
                number_format((float) $adjustment->new_rent, 0, ',', '.'),
                $adjustment->state()->value,
            ));

            $running = (float) $adjustment->new_rent;
        }

        $this->newLine();
        $this->line(sprintf(
            'Canon que resulta de la cadena: $%s',
            number_format($running, 0, ',', '.'),
        ));
    }
}
