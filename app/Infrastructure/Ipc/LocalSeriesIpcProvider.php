<?php

namespace App\Infrastructure\Ipc;

use App\Contracts\Ipc\IpcProvider;
use App\Dto\Geography;
use App\Dto\IpcObservation;
use App\Models\Geography as GeographyModel;
use App\Models\IpcSeries;
use App\Services\Ipc\IpcSeriesRepository;

/**
 * Proveedor del IPC respaldado por la copia local.
 *
 * Es el que usa la aplicacion en produccion, y es un adaptador tambien, aunque
 * no lo parezca: traduce entre lo que el dominio pide (una serie de observaciones
 * del IPC) y lo que la base de datos puede dar (columnas de ipc_series).
 *
 * Que sea el provider por defecto y no el del DANE es una decision de
 * disponibilidad. Un reajuste no puede depender de que un servidor de terceros
 * responda en ese momento: el DANE devuelve 502 de forma intermitente, y una
 * carta de reajuste que se emite por correo no puede fallar porque el DANE este
 * caido.
 *
 * El flujo queda de una sola via: el comando de sincronizacion usa el adaptador
 * del DANE para traer datos y los guarda aqui; todo lo demas lee de aqui.
 */
class LocalSeriesIpcProvider implements IpcProvider
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function name(): string
    {
        return 'Copia local del DANE';
    }

    /**
     * @return list<IpcObservation>
     */
    public function fetchSeries(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array
    {
        return $this->series
            ->series($geographyCode, $fromYear, $toYear)
            ->map(fn (IpcSeries $row): IpcObservation => $row->toObservation())
            ->all();
    }

    /**
     * @return list<Geography>
     */
    public function fetchGeographies(): array
    {
        return GeographyModel::query()
            ->withIpc()
            ->get()
            ->map(fn (GeographyModel $geography): Geography => new Geography(
                code: $geography->code,
                name: $geography->name,
                department: $geography->department,
                hasIpc: $geography->has_ipc,
                source: $geography->source,
            ))
            ->all();
    }

    /**
     * @return list<int>
     */
    public function fetchAvailableYears(): array
    {
        return $this->series->availableYears();
    }
}
