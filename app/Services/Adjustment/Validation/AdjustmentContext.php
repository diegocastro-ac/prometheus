<?php

namespace App\Services\Adjustment\Validation;

use App\Dto\IpcSnapshot;
use App\Models\Rental;

/**
 * Todo lo que las reglas necesitan saber de un reajuste en curso.
 *
 * Un value object y no un array porque las reglas se leen mucho mejor con
 * $context->rental que con $context['rental'], y porque asi el conjunto de
 * datos que cada regla puede mirar queda explicito en un solo sitio.
 *
 * El contexto existe antes de que exista el registro: las reglas corren en el
 * formulario, mientras el usuario elige, y despues vuelven a correr al guardar.
 * Por eso el snapshot del IPC se recibe ya resuelto en lugar de que cada regla
 * lo consulte por su cuenta.
 */
final readonly class AdjustmentContext
{
    public function __construct(
        public Rental $rental,
        public \DateTimeInterface $effectiveFrom,
        public ?IpcSnapshot $ipc,
        public float $requestedRent,
        public ?float $previousRent = null,
        public ?float $pactadoPercentage = null,
        public bool $acknowledgedDeviation = false,
        public ?string $notes = null,
        public bool $isUpdate = false,
        public ?\App\Enums\NotificationMethod $notificationMethod = null,
        public ?\DateTimeInterface $notifiedOn = null,
    ) {}

    /**
     * El canon contra el que se compara: el derivado de la cadena si existe, y
     * el vigente si no.
     */
    public function baseRent(): float
    {
        return $this->previousRent
            ?? $this->rental->anchorRent()
            ?? (float) $this->rental->monthly_amount;
    }

    public function hasIpc(): bool
    {
        return $this->ipc !== null;
    }

    /**
     * El reajuste se registra antes de cumplir los doce meses bajo un mismo precio.
     *
     * El articulo 20 cuenta el plazo desde el ultimo cambio de canon, no desde
     * el inicio del contrato. Un alquiler reajustado en octubre de 2025 vuelve a
     * cumplir doce meses en octubre de 2026.
     */
    public function timingIsEarly(): bool
    {
        return $this->rental->nextAdjustmentDueDate()
            ->startOfDay()
            ->greaterThan($this->effectiveFrom);
    }

    /**
     * El IPC quedaria fuera del articulo 20 por eleccion de la parte.
     */
    public function deviatesFromArticle20(): bool
    {
        if ($this->ipc === null) {
            return false;
        }

        if (! $this->ipc->isStatutory) {
            return true;
        }

        return $this->ipc->year !== ((int) $this->effectiveFrom->format('Y') - 1);
    }

    public function withIpc(?IpcSnapshot $ipc): self
    {
        return new self(
            rental: $this->rental,
            effectiveFrom: $this->effectiveFrom,
            ipc: $ipc,
            requestedRent: $this->requestedRent,
            previousRent: $this->previousRent,
            pactadoPercentage: $this->pactadoPercentage,
            acknowledgedDeviation: $this->acknowledgedDeviation,
            notes: $this->notes,
            isUpdate: $this->isUpdate,
            notificationMethod: $this->notificationMethod,
            notifiedOn: $this->notifiedOn,
        );
    }
}
