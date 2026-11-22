<?php

namespace App\Infrastructure\Ipc;

use App\Contracts\Ipc\IpcProvider;
use App\Dto\Geography;
use App\Dto\IpcObservation;

/**
 * Proveedor en memoria, para pruebas.
 *
 * Existe para poder probar la cadena de reajustes sin tocar la base de datos ni
 * la red. El valor real no esta en ser rapido: esta en que un test del articulo
 * 20 necesita fijar exactamente que IPC hay disponible, y sembrar ipc_series con
 * un unico dato de diciembre obliga a pensar en filas que no son el objeto del
 * test.
 *
 * La serie que trae por defecto reproduce los valores oficiales que se
 * verificaron contra el DANE, para que un test no verde signifique lo mismo en
 * esta aplicacion que en el mundo real.
 */
class InMemoryIpcProvider implements IpcProvider
{
    /**
     * @var list<IpcObservation>
     */
    private array $observations = [];

    /**
     * @var list<Geography>
     */
    private array $geographies = [];

    /**
     * @param  list<IpcObservation>  $observations
     */
    public function __construct(array $observations = [], array $geographies = [])
    {
        $this->observations = $observations;
        $this->geographies = $geographies;
    }

    public function name(): string
    {
        return 'Proveedor en memoria';
    }

    /**
     * @return list<IpcObservation>
     */
    public function fetchSeries(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array
    {
        $code = Geography::normaliseCode($geographyCode) ?? $geographyCode;

        return array_values(array_filter(
            $this->observations,
            function (IpcObservation $observation) use ($code, $fromYear, $toYear): bool {
                if ($observation->geographyCode !== $code) {
                    return false;
                }

                if ($fromYear !== null && $observation->year < $fromYear) {
                    return false;
                }

                return ! ($toYear !== null && $observation->year > $toYear);
            },
        ));
    }

    /**
     * @return list<Geography>
     */
    public function fetchGeographies(): array
    {
        return $this->geographies;
    }

    /**
     * @return list<int>
     */
    public function fetchAvailableYears(): array
    {
        $years = array_unique(array_map(
            fn (IpcObservation $observation): int => $observation->year,
            $this->observations,
        ));

        rsort($years);

        return array_values($years);
    }

    /**
     * @param  list<IpcObservation>  $observations
     */
    public function withSeries(array $observations): self
    {
        $clone = clone $this;
        $clone->observations = $observations;

        return $clone;
    }

    /**
     * @param  list<Geography>  $geographies
     */
    public function withGeographies(array $geographies): self
    {
        $clone = clone $this;
        $clone->geographies = $geographies;

        return $clone;
    }
}
