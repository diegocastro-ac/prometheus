<?php

namespace App\Services\Adjustment\Strategies;

use App\Dto\IpcSnapshot;
use App\Services\Ipc\IpcSeriesRepository;
use RuntimeException;

/**
 * Porcentaje deducido de la razon entre dos indices.
 *
 * Es la forma en que muchos contratos redactan el reajuste: "el canon se
 * ajustara en la misma proporcion en que varie el IPC, tomando como base el
 * indice del mes en que se firma". Se calcula dividiendo el indice del periodo de
 * corte entre el indice del periodo base.
 *
 * Se incluye como estrategia alternativa y con una advertencia deliberada: no
 * puede alimentar el tope.
 *
 * El motivo es que no es lo que dice el articulo 20. El articulo toma una
 * variacion de un ano calendario completo; este metodo toma una razon entre dos
 * puntos cualesquiera de la serie, y con un periodo base que no sea diciembre da
 * un numero distinto. En diciembre los dos coinciden, porque la razon entre
 * dos indices de diciembre reproduce la variacion anual. En cualquier otro
 * momento no.
 *
 * Sirve para dos cosas: comparar, y para un arrendador que pactara expresamente
 * el metodo por indices. Para lo segundo, el resultado tiene que viajar marcado
 * como informativo, que es lo que hace el flag isStatutory del snapshot.
 */
class IndexRatioStrategy implements IpcBasisStrategy
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function name(): string
    {
        return 'index_ratio';
    }

    public function isStatutory(): bool
    {
        return false;
    }

    public function description(): string
    {
        return __('ipc.strategies.index_ratio');
    }

    /**
     * El periodo base es el mes inmediatamente anterior al de vigencia.
     *
     * Es la convencion mas frecuente en contratos, y la que hace comparables dos
     * reajustes seguidos: el canon del mes de corte sobre el canon del mes
     * anterior.
     */
    public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
    {
        $code = $geographyCode ?? \App\Dto\Geography::nationalCode();

        $periods = $this->series->series($code);
        $cutoffKey = $effectiveFrom->format('Y-m');

        $cutoff = $periods->first(fn ($row): bool => $row->periodKey() === $cutoffKey);

        if ($cutoff === null) {
            throw new RuntimeException(__('adjustment_rules.has_ipc_data.missing_index', [
                'period' => $effectiveFrom->format('m/Y'),
                'geography' => $code,
            ]));
        }

        $base = $periods
            ->filter(fn ($row): bool => $row->periodKey() < $cutoffKey)
            ->last();

        if ($base === null) {
            throw new RuntimeException(sprintf(
                'No hay ningun indice del IPC anterior a %s para calcular la razon.',
                $cutoffKey,
            ));
        }

        $baseIndex = (float) $base->index_value;
        $cutoffIndex = (float) $cutoff->index_value;

        if ($baseIndex <= 0) {
            throw new RuntimeException('El indice base del IPC es cero, no se puede derivar una razon.');
        }

        $percentage = round((($cutoffIndex / $baseIndex) - 1) * 100, 2);

        return IpcSnapshot::fromObservation(
            observation: $cutoff->toObservation(),
            percentage: $percentage,
            isStatutory: false,
            sourceName: $cutoff->source_name ?? 'DANE',
            sourceUrl: $cutoff->source_url,
            syncedAt: $cutoff->synced_at,
        )->withExplanation(sprintf(
            'Razon entre el indice de %s (%s) y el de %s (%s). No es la variacion anual que '
            .'ordena el articulo 20: es un dato informativo.',
            $cutoff->periodKey(),
            number_format($cutoffIndex, 2, ',', '.'),
            $base->periodKey(),
            number_format($baseIndex, 2, ',', '.'),
        ));
    }
}
