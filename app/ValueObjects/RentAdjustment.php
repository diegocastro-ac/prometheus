<?php

namespace App\ValueObjects;

use App\Models\IpcRate;

/**
 * Reajuste anual del canon de un alquiler.
 *
 * El articulo 20 de la Ley 820 de 2003 permite incrementar el canon hasta el
 * 100 % del IPC del ano calendario inmediatamente anterior. Este value object
 * captura el resultado de aplicar esa regla, con dos decisiones que conviene
 * tener explicitas.
 *
 * Sobre el encadenado: previousRent es el canon que RIGIA, no el canon original
 * del contrato. "Cada doce (12) meses de ejecucion del contrato bajo un mismo
 * precio": el precio vigente durante esos doce meses es el del reajuste anterior,
 * y el tope del ano siguiente se calcula sobre el. Encadenar desde el canon base
 * equivaldria a renunciar a buena parte del IPC del ultimo ano.
 *
 * Sobre el exceso: el articulo lo formula como limite, pero la practica admite
 * pactar mas y la jurisprudencia no pudo verificarse al construir el modulo. Por
 * eso exceedsCap() informa y no bloquea. Lo que si es condicion del articulo es
 * la comunicacion, y eso se comprueba en otro punto.
 */
final readonly class RentAdjustment
{
    private function __construct(
        public float $previousRent,
        public float $requestedRent,
        public float $legalCapRent,
        public float $ipcPercentage,
        public int $ipcYear,
        public string $ipcSourceName,
        public string $ipcSourceUrl,
        public ?float $anchorRent = null,
        public ?float $legalPercentage = null,
        public ?float $appliedPercentage = null,
    ) {}

    /**
     * Calcula el reajuste con la regla del articulo 20.
     *
     * @param  float|null  $pactadoPercentage  lo que las partes acuerdan por
     *                                         debajo del IPC. El "hasta" del
     *                                         articulo lo admite, y es lo mas
     *                                         comun en la practica.
     */
    public static function fromIpc(
        float $previousRent,
        float $requestedRent,
        IpcRate $ipc,
        ?float $pactadoPercentage = null,
        ?float $anchorRent = null,
    ): self {
        $legal = (float) $ipc->percentage;
        $applied = $pactadoPercentage === null ? $legal : min($pactadoPercentage, $legal);

        return new self(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            // El tope que fija la ley es el del IPC. Pactar menos baja la cifra
            // acordada, que es otra cosa, y por eso el tope no cambia.
            // Se redondea al peso: un canon con centavos no existe.
            legalCapRent: round($previousRent * (1 + ($legal / 100)), 0),
            ipcPercentage: $applied,
            ipcYear: $ipc->year,
            ipcSourceName: $ipc->source_name,
            ipcSourceUrl: $ipc->source_url,
            anchorRent: $anchorRent,
            legalPercentage: $legal,
            appliedPercentage: $applied,
        );
    }

    /**
     * Reconstruye un reajuste ya calculado y guardado.
     *
     * La carta de reajuste lee de la base de datos, no vuelve a calcular. Por eso
     * hace falta esta entrada: los datos del tope vienen congelados y no se
     * vuelven a derivar del IPC, que para entonces pudo corregirse.
     */
    public static function fromFrozen(
        float $previousRent,
        float $requestedRent,
        float $legalCapRent,
        float $ipcPercentage,
        int $ipcYear,
        string $ipcSourceName,
        string $ipcSourceUrl,
        ?float $anchorRent = null,
        ?float $legalPercentage = null,
        ?float $appliedPercentage = null,
    ): self {
        return new self(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            legalCapRent: $legalCapRent,
            ipcPercentage: $ipcPercentage,
            ipcYear: $ipcYear,
            ipcSourceName: $ipcSourceName,
            ipcSourceUrl: $ipcSourceUrl,
            anchorRent: $anchorRent,
            legalPercentage: $legalPercentage ?? $ipcPercentage,
            appliedPercentage: $appliedPercentage ?? $ipcPercentage,
        );
    }

    /**
     * Diferencia entre el canon pedido y el tope legal. Cero cuando el
     * incremento esta dentro del IPC.
     */
    public function excessOverCap(): float
    {
        return max(0.0, round($this->requestedRent - $this->legalCapRent, 0));
    }

    /**
     * Un incremento negativo no es un reajuste: es una rebaja y no pasa por el
     * tope del IPC.
     */
    public function isIncrease(): bool
    {
        return $this->requestedRent > $this->previousRent;
    }

    public function exceedsCap(): bool
    {
        return $this->isIncrease() && $this->requestedRent > $this->legalCapRent;
    }

    /**
     * Si el IPC del articulo 20 y el aplicado difieren, es porque hubo pacto.
     */
    public function hasPactadoCap(): bool
    {
        return $this->legalPercentage !== null
            && $this->appliedPercentage !== null
            && $this->legalPercentage > $this->appliedPercentage;
    }

    /**
     * El incremento acumulado sobre el canon original, no sobre el ultimo.
     *
     * Es lo que compara el inquilino entre lo que pago al principio y lo que
     * paga hoy, y no es el porcentaje del ultimo reajuste.
     */
    public function cumulativeIncreasePercentage(): float
    {
        $anchor = $this->anchorRent ?? $this->previousRent;

        if ($anchor <= 0) {
            return 0.0;
        }

        return round((($this->requestedRent - $anchor) / $anchor) * 100, 2);
    }

    public function increase(): float
    {
        return round($this->requestedRent - $this->previousRent, 0);
    }

    public function increasePercentage(): float
    {
        if ($this->previousRent <= 0) {
            return 0.0;
        }

        return round((($this->requestedRent - $this->previousRent) / $this->previousRent) * 100, 2);
    }

    /**
     * Porcentaje que representa el IPC como factor, para mostrarlo como "1,0928".
     */
    public function ipcFactor(): string
    {
        return number_format(1 + ($this->ipcPercentage / 100), 4, ',', '.');
    }
}
