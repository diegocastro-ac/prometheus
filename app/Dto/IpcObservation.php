<?php

namespace App\Dto;

/**
 * Un dato del IPC tal como vino del DANE, ya normalizado.
 *
 * El adaptador entrega estos objetos y no arrays: la conversion de "193,28" a
 * 193.28, de "Sep" a 9 y de 8001 a "08001" ocurre una sola vez, en el borde del
 * sistema, y a partir de aqui nadie vuelve a mirar texto crudo del DANE.
 *
 * Los tres campos de variacion guardan lo que el CDA llama asi:
 *
 *   variationMonthly -> "Variacion Mensual": contra el mes anterior.
 *   variationYoY     -> "Variacion Anual": contra el mismo mes del ano anterior.
 *   variationYear    -> "Variacion Ano Corrido": acumulada desde diciembre.
 *
 * En diciembre los dos ultimos coinciden, y eso es exactamente el dato que pide
 * el articulo 20: la variacion del ano calendario. El constructor no lo
 * comprueba porque el CDA puede traer celdas vacias en los primeros periodos de
 * la serie; la verificacion vive donde la regla importa, no aqui.
 */
final readonly class IpcObservation
{
    public function __construct(
        public string $geographyCode,
        public string $geographyName,
        public int $year,
        public int $month,
        public float $index,
        public ?float $variationMonthly = null,
        public ?float $variationYoY = null,
        public ?float $variationYear = null,
    ) {}

    /**
     * @return array{anio: int, mes: int}
     */
    public function period(): array
    {
        return ['anio' => $this->year, 'mes' => $this->month];
    }

    /**
     * El periodo en formato YYYY-MM, util para ordenar y comparar.
     */
    public function periodKey(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function isDecember(): bool
    {
        return $this->month === 12;
    }

    /**
     * La variacion que representa el ano calendario completo.
     *
     * Se prefiere la variacion acumulada del ano y se cae a la interanual solo
     * si la primera vinio vacia. En diciembre el DANE publica las dos con el
     * mismo valor, asi que la eleccion no altera el resultado; se documenta
     * para que quede claro que no es arbitraria.
     */
    public function calendarYearVariation(): ?float
    {
        return $this->variationYear ?? $this->variationYoY;
    }
}
