<?php

namespace App\Services\Adjustment\Strategies;

use App\Dto\IpcSnapshot;
use App\Services\Ipc\IpcSeriesRepository;
use RuntimeException;

/**
 * La estrategia que aplica el articulo 20.
 *
 * El articulo dice, literalmente: "el incremento que haya tenido el indice de
 * precios al consumidor en el ano calendario inmediatamente anterior a aquel en
 * que deba efectuarse el reajuste del canon".
 *
 * De ahi salen tres decisiones, y las tres importan:
 *
 * 1. Se toma el ano ANTERIOR al de vigencia, no el de vigencia. Un reajuste que
 *    empieza en octubre de 2026 usa el IPC de 2025.
 *
 * 2. Se toma la variacion ANUAL, que es la de diciembre contra diciembre del
 *    ano anterior. No la acumulada a la fecha del reajuste: si se usara esa, un
 *    reajuste de octubre de 2026 compararia enero con diciembre y daria una cifra
 *    que la ley no contempla.
 *
 * 3. Se toma la serie NACIONAL por defecto. El articulo no menciona ciudades; el
 *    total nacional es lo que el DANE certifica como "el indice de precios al
 *    consumidor". Cuando se pide una ciudad concreta, el resultado se marca como
 *    no legal, y el juego de palabras "no legal" es deliberado: significa que no
 *    es lo que el articulo ordena, no que sea invalido.
 */
class AnnualVariationStrategy implements IpcBasisStrategy
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function name(): string
    {
        return 'annual_variation';
    }

    public function isStatutory(): bool
    {
        return true;
    }

    public function description(): string
    {
        return __('ipc.strategies.annual_variation');
    }

    public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
    {
        $ipcYear = (int) $effectiveFrom->format('Y') - 1;

        $isNational = $geographyCode === null
            || $geographyCode === \App\Dto\Geography::nationalCode();

        $code = $geographyCode ?? \App\Dto\Geography::nationalCode();

        $observation = $this->series->statutoryForAdjustmentYear($code, (int) $effectiveFrom->format('Y'));

        if ($observation === null) {
            throw new RuntimeException(__('adjustment_rules.has_ipc_data.missing_short', [
                'year' => $ipcYear,
                'geography' => $isNational ? 'total nacional' : 'ciudad '.$code,
            ]));
        }

        $variation = $observation->calendarYearVariation();

        if ($variation === null) {
            throw new RuntimeException(sprintf(
                'El dato del DANE para %s, diciembre de %d, viene sin variacion anual. No se '
                .'puede derivar el tope sin ella.',
                $observation->geography_name,
                $ipcYear,
            ));
        }

        $snapshot = IpcSnapshot::fromObservation(
            observation: $observation->toObservation(),
            percentage: (float) $variation,
            // La estrategia produce la cifra que pide el articulo 20, pero eso no
            // significa que esta estrategia lo diga. Lo que lo dice es
            // de que geografia salio el dato: el total nacional es el que la ley
            // contempla, una ciudad es un dato acordado entre las partes.
            isStatutory: $isNational,
            sourceName: $observation->source_name ?? 'DANE',
            sourceUrl: $observation->source_url,
            syncedAt: $observation->synced_at,
        );

        return $snapshot->withExplanation(sprintf(
            'Variacion anual de %s, diciembre de %d (DANE).',
            $observation->geography_name,
            $ipcYear,
        ));
    }
}
