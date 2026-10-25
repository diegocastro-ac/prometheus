<?php

namespace App\Filament\Support;

/**
 * Las cifras del cálculo, ya resueltas.
 *
 * Existe para que la tabla y el formulario no tengan que recalcular nada, y para
 * que una decisión de presentación no se mezcle con una aritmética. Quien la
 * construye entrega números; quien la dibuja solo decide cómo se ven.
 *
 * Se construye con Named Constructor, porque los cinco campos no se pueden
 * inventar de forma fiable uno a uno: `cap` e `increase` siempre dependen de
 * `currentRent` y `percentage`, y construirlos sueltos invitaría a que alguien
 * los escribiera a mano y dejara que no cuadraran.
 */
final readonly class AdjustmentCalculation
{
    /**
     * @param  float  $currentRent  canon vigente: el monto sobre el que se aplica el IPC.
     * @param  float  $percentage   IPC aplicado, en porcentaje (5.10 significa 5,10 %).
     * @param  int  $year  año calendario del IPC aplicado.
     * @param  string  $geographyName  nombre de la geografía de la que sale el IPC.
     * @param  float  $increase  incremento que corresponde a ese porcentaje.
     * @param  float  $cap  canon resultante, que es el tope del artículo 20.
     * @param  float|null  $requestedRent  canon que el usuario escribió, si ya lo escribió.
     * @param  float|null  $pactadoPct  porcentaje pactado por las partes, si lo pactaron.
     */
    private function __construct(
        public float $currentRent,
        public float $percentage,
        public int $year,
        public string $geographyName,
        public float $increase,
        public float $cap,
        public ?float $requestedRent,
        public ?float $pactadoPct,
    ) {}

    /**
     * Construye las cifras aplicando el IPC al canon vigente.
     */
    public static function make(
        float $currentRent,
        float $percentage,
        int $year,
        string $geographyName,
        ?float $requestedRent = null,
        ?float $pactadoPct = null,
    ): self {
        $increase = round($currentRent * ($percentage / 100), 0);

        return new self(
            currentRent: $currentRent,
            percentage: $percentage,
            year: $year,
            geographyName: $geographyName,
            increase: $increase,
            cap: $currentRent + $increase,
            requestedRent: $requestedRent,
            pactadoPct: $pactadoPct,
        );
    }

    /**
     * Cuánto se aparta el canon escrito del tope de la ley.
     *
     * Positivo es exceso, negativo es lo que deja de aplicarse. Con el tope
     * exacto devuelve 0, y quien llama usa eso para no pintar una diferencia
     * que no es una diferencia.
     */
    public function differenceFromCap(): float
    {
        if ($this->requestedRent === null) {
            return 0.0;
        }

        return round($this->requestedRent - $this->cap, 0);
    }

    public function exceedsCap(): bool
    {
        return $this->differenceFromCap() >= 1;
    }

    public function belowCap(): bool
    {
        return $this->differenceFromCap() <= -1;
    }

    /**
     * El canon final después de aplicar el pacto, si lo hay.
     */
    public function appliedRent(): float
    {
        if ($this->pactadoPct !== null && $this->pactadoPct < $this->percentage) {
            return round($this->currentRent * (1 + ($this->pactadoPct / 100)), 0);
        }

        return $this->cap;
    }
}