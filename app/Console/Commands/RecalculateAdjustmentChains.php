<?php

namespace App\Console\Commands;

use App\Commands\Adjustment\RecalculateRentAdjustmentChain;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
 *
 * La simulacion (--dry-run) compara el memento de la cadena con el resultado
 * del calculo y no escribe: antes solo imprimia el estado actual y, sin
 * --rental, seguia reprocesando de verdad.
 */
class RecalculateAdjustmentChains extends Command
{
    protected $signature = 'rent-adjustments:recalculate
                            {--rental= : Limita el recalculo a un alquiler concreto}
                            {--dry-run : Muestra lo que cambiaria sin escribir nada}';

    protected $description = 'Reprocesa la cadena de reajustes de los alquileres desde su canon base';

    public function handle(AdjustmentChainRecalculator $recalculator): int
    {
        if ($this->option('rental')) {
            return $this->recalculateOne((int) $this->option('rental'), $recalculator);
        }

        if ($this->option('dry-run')) {
            return $this->previewAll($recalculator);
        }

        $rentals = $this->rentalsWithAdjustments()->count();

        if ($rentals === 0) {
            $this->info('No hay alquileres con reajustes registrados.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Reprocesando %d alquileres con reajustes...', $rentals));

        $touched = 0;
        $changed = 0;

        $this->rentalsWithAdjustments()
            ->chunkById(50, function ($chunk) use (&$touched, &$changed, $recalculator): void {
                foreach ($chunk as $rental) {
                    $result = (new RecalculateRentAdjustmentChain($recalculator, $rental))->execute();

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

    private function recalculateOne(int $rentalId, AdjustmentChainRecalculator $recalculator): int
    {
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
        $this->line(sprintf('Canon base: %s', $this->money($anchor)));

        if ($this->option('dry-run')) {
            return $this->previewOne($rental, $recalculator);
        }

        $result = (new RecalculateRentAdjustmentChain($recalculator, $rental))->execute();

        $this->line(sprintf(
            '%d reajustes actualizados. Canon vigente del alquiler: %s',
            $result['touched'],
            $this->money($result['current_rent']),
        ));

        return self::SUCCESS;
    }

    private function previewAll(AdjustmentChainRecalculator $recalculator): int
    {
        $rentals = $this->rentalsWithAdjustments()->count();

        if ($rentals === 0) {
            $this->info('No hay alquileres con reajustes registrados.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Simulando el reproceso de %d alquileres con reajustes...', $rentals));

        $result = $recalculator->previewAll(function (): void {
            $this->output->write('.');
        });

        $this->newLine();

        $this->info(sprintf(
            '%d alquileres revisados, %d reajustes cambiarian en %d alquileres.',
            $result['rentals'],
            $result['touched'],
            $result['changed_rentals'],
        ));

        if ($result['touched'] === 0) {
            $this->line('  Nada cambiaria: las cadenas ya estan coherentes con sus anclas.');
        } else {
            $this->line('  Simulacion: no se escribio ningun cambio.');
        }

        return self::SUCCESS;
    }

    private function previewOne(Rental $rental, AdjustmentChainRecalculator $recalculator): int
    {
        $preview = $recalculator->preview($rental);

        $this->newLine();
        $this->line('Simulacion: no se escribira ningun cambio.');

        if ($preview['touched'] === 0) {
            $this->line('La cadena ya esta coherente con su ancla: no cambiaria ningun reajuste.');

            return self::SUCCESS;
        }

        $this->line('Cambios previstos:');

        foreach ($preview['changes'] as $change) {
            $before = $change['before'];
            $after = $change['after'];

            $this->line(sprintf(
                '  #%d -> #%d  canon de entrada %s -> %s  tope %s -> %s  exceso %s -> %s',
                $before->sequence,
                (int) $after['sequence'],
                $this->money($before->previousRent),
                $this->money((float) $after['previous_rent']),
                $this->money($before->legalCapRent),
                $this->money((float) $after['legal_cap_rent']),
                $this->money($before->excessOverCap),
                $this->money((float) $after['excess_over_cap']),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '%d reajustes cambiarian. Canon vigente resultante: %s',
            $preview['touched'],
            $this->money($preview['current_rent']),
        ));

        return self::SUCCESS;
    }

    private function rentalsWithAdjustments(): Builder
    {
        return Rental::query()
            ->whereNotNull('anchor_monthly_amount')
            ->whereHas('rentAdjustments');
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }
}
