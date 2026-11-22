<?php

namespace App\Contracts\Ipc;

use App\Dto\Geography;
use App\Dto\IpcObservation;

/**
 * Fuente de datos del IPC (puerto del patron Adapter).
 *
 * Este contrato es la frontera del sistema con el mundo exterior. Todo lo que
 * esta detras -el CDA de Pentaho, sus tablas internas, sus comas decimales, sus
 * ceros perdidos y sus 502- es un detalle de una implementacion. Lo que el
 * dominio conoce es este interfaz, y por eso se puede sustituir el DANE por una
 * tabla local, por un archivo o por un doble de prueba sin tocar una linea de
 * la regla del articulo 20.
 *
 * Las implementaciones:
 *
 *   DaneCdaIpcProvider     el adaptador real, contra el CDA del DANE.
 *   LocalSeriesIpcProvider lee la copia local, que es la que usa la aplicacion.
 *   InMemoryIpcProvider    para pruebas.
 */
interface IpcProvider
{
    /**
     * La serie mensual del IPC de una geografia.
     *
     * @param  string  $geographyCode  '50' para el total nacional, o el DIVIPOLA
     *                                 de cinco digitos de la ciudad.
     * @param  int|null  $fromYear  limite inferior del rango de anos.
     * @param  int|null  $toYear  limite superior del rango de anos.
     * @return list<IpcObservation> vacio si la combinacion no existe.
     */
    public function fetchSeries(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array;

    /**
     * Las geografias sobre las que el DANE publica IPC.
     *
     * Es el unico origen de los codigos de ciudad de la aplicacion. Nada asume
     * que un DIVIPOLA cualquiera tenga IPC: si no viene de aqui, no se ofrece.
     *
     * @return list<Geography>
     */
    public function fetchGeographies(): array;

    /**
     * Los anos que el DANE tiene disponibles.
     *
     * @return list<int> descendente.
     */
    public function fetchAvailableYears(): array;

    /**
     * Nombre de la fuente, para mostrarlo en la interfaz y en los logs.
     */
    public function name(): string;
}
