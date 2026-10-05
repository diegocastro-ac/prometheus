<?php

namespace App\ValueObjects;

use App\Models\IpcRate;

/**
 * Reajuste anual del canon de un alquiler.
 *
 * El articulo 20 de la Ley 820 de 2003 permite incrementar el canon hasta el
 * 100 % del IPC del ano calendario inmediatamente anterior. Dos reglas de este
 * valor lo hacen explicito:
 *
 * - El tope no se aplica solo. Si el incremento supera el IPC, la ley no lo
 *   veda de forma categorica, pero solo opera si hay acuerdo escrito entre las
 *   partes, asi que el documento debe decirlo en vez de aplicarlo en silencio.
 * - El IPC de un ano no aplica a un reajuste de ese mismo ano: el de 2025
 *   reajuste a un canon que se modifica en 2026.
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
    ) {}

    public static function fromIpc(
        float $previousRent,
        float $requestedRent,
        IpcRate $ipc,
    ): self {
        $factor = 1 + ($ipc->percentage / 100);

        return new self(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            // Se redondea al peso: un canon con centavos no existe.
            legalCapRent: round($previousRent * $factor, 0),
            ipcPercentage: $ipc->percentage,
            ipcYear: $ipc->year,
            ipcSourceName: $ipc->source_name,
            ipcSourceUrl: $ipc->source_url,
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
    ): self {
        return new self(
            previousRent: $previousRent,
            requestedRent: $requestedRent,
            legalCapRent: $legalCapRent,
            ipcPercentage: $ipcPercentage,
            ipcYear: $ipcYear,
            ipcSourceName: $ipcSourceName,
            ipcSourceUrl: $ipcSourceUrl,
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

    public function requiresWrittenAgreement(): bool
    {
        return $this->exceedsCap();
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
