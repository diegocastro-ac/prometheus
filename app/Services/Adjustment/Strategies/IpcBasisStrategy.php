<?php

namespace App\Services\Adjustment\Strategies;

use App\Dto\IpcSnapshot;

/**
 * Como se deriva el porcentaje de un reajuste a partir de la serie del IPC.
 *
 * Hay mas de una forma matematicamente valida de hacerlo, y por eso esto es un
 * patron Strategy y no una funcion. La eleccion no es de gusto: depende de que lo
 * que diga el articulo 20 sea o no calculable con ese metodo.
 *
 * La distincion que separa las implementaciones es isStatutory. Solo una
 * estrategia puede decir true, y esa es la que puede alimentar el tope legal.
 * Las demas pueden ser mas precisas o mas intuitivas, pero no son lo que la ley
 * ordena usar, y por eso sus resultados no pueden vestirse de "tope legal" en la
 * carta de reajuste.
 */
interface IpcBasisStrategy
{
    /**
     * Nombre corto, para seleccionarla por configuracion o por formulario.
     */
    public function name(): string;

    /**
     * Resuelve el IPC aplicable a un reajuste.
     *
     * @param  \DateTimeInterface  $effectiveFrom  la fecha en que regira el canon.
     * @param  string|null  $geographyCode  la geografia elegida, o null para la legal.
     */
    public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot;

    /**
     * Si lo que produce esta estrategia es lo que exige el articulo 20.
     */
    public function isStatutory(): bool;

    /**
     * Para que la interfaz lo muestre al usuario antes de elegir.
     */
    public function description(): string;
}
