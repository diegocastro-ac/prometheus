<?php

namespace App\Console\Commands;

use App\Dto\Geography;
use App\Events\IpcSeriesSynchronized;
use App\Infrastructure\Ipc\DaneCdaIpcProvider;
use App\Services\Ipc\IpcSynchronizer;
use Illuminate\Console\Command;

/**
 * Sincroniza la serie del IPC del DANE a la base de datos local.
 *
 * El comando es el unico punto por el que entra data externa al sistema. Todo lo
 * demas lee de la copia local, y esa es la razon por la que existe: el CDA del
 * DANE no tiene garantia de disponibilidad ni de estabilidad, y una carta de
 * reajuste no puede depender de que un servidor de terceros responda en ese
 * momento.
 *
 * Al terminar dispara el evento IpcSeriesSynchronized, que reconstruye las tasas
 * anuales y reprocesa las cadenas de reajuste. El comando no sabe que esos dos
 * pasos existen.
 */
class SyncIpcDane extends Command
{
    protected $signature = 'ipc:sync
                            {--city= : Sincroniza solo el IPC de una ciudad (DIVIPOLA de 5 digitos)}
                            {--cities : Sincroniza el total nacional y todas las ciudades con IPC}
                            {--from= : Primer ano a traer}
                            {--no-events : No dispara la reconstruccion de tasas ni el recalculo}';

    protected $description = 'Sincroniza la serie mensual del IPC del DANE a la base de datos local';

    public function handle(DaneCdaIpcProvider $provider): int
    {
        $synchronizer = new IpcSynchronizer($provider);

        $fromYear = $this->option('from') !== null ? (int) $this->option('from') : null;

        if ($this->option('city')) {
            $code = Geography::normaliseCode($this->option('city'));

            if ($code === null) {
                $this->error('El codigo de ciudad no es valido. Debe ser el DIVIPOLA de cinco digitos, por ejemplo 20001 para Valledupar.');

                return self::FAILURE;
            }

            $result = $synchronizer->syncGeography($code, $fromYear);

            $this->report($result);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Sincronizando el total nacional del DANE...');

        $results = [$synchronizer->syncGeography(Geography::nationalCode(), $fromYear)];

        if ($this->option('cities')) {
            $cities = $synchronizer->citiesWithIpc();

            $this->info(sprintf('Sincronizando %d ciudades con IPC...', count($cities)));

            $bar = $this->output->createProgressBar(count($cities));
            $bar->start();

            foreach ($cities as $code) {
                $results[] = $synchronizer->syncGeography($code, $fromYear);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        foreach ($results as $result) {
            $this->report($result);
        }

        $successful = array_values(array_filter($results, fn (array $r): bool => $r['ok']));

        if ($successful === []) {
            $this->error('Ninguna geografia se pudo sincronizar. Se conserva la ultima serie buena.');

            return self::FAILURE;
        }

        // Si el total nacional fallo, no se reconstruye nada: las tasas anuales
        // salen de ahi y reconstruir con una serie parcial dejaria anos sueltos.
        $nationalOk = $results[0]['ok'];

        if ($nationalOk && ! $this->option('no-events')) {
            // event() y no el trait Dispatchable: este construye con argumentos
            // posicionales y no acepta los con nombre del constructor promoted.
            event(new IpcSeriesSynchronized(
                geographies: array_column($successful, 'geography'),
                storedPeriods: array_sum(array_column($successful, 'stored')),
                nationalOnly: ! $this->option('cities'),
            ));
        }

        return $nationalOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result): void
    {
        $label = $result['geography'] === Geography::nationalCode()
            ? 'Nacional'
            : $result['geography'];

        if ($result['ok']) {
            $this->line(sprintf(
                '  <info>OK</info>   %-8s %d periodos guardados. %s',
                $label,
                $result['stored'],
                $result['message'],
            ));

            return;
        }

        $this->line(sprintf('  <error>FALLA</error> %-8s %s', $label, $result['message']));
    }
}
