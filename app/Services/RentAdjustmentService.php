<?php

namespace App\Services;

use App\Dto\IpcSnapshot;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentMediator;

/**
 * Puerta publica del subsistema de reajustes.
 *
 * Conserva las firmas que ya usaban las pantallas, la fachada, el seeder y las
 * pruebas. La coordinacion entre validacion, IPC, persistencia y recalculo vive
 * en AdjustmentMediator (patron Mediator); esta clase solo delega.
 */
class RentAdjustmentService
{
    public function __construct(
        private readonly AdjustmentMediator $mediator,
    ) {}

    /**
     * Registra un reajuste para el canon que ya rigio en el alquiler.
     */
    public function record(
        Rental $rental,
        float $newRent,
        \DateTimeInterface $effectiveFrom,
        ?int $ipcYear = null,
        ?string $notes = null,
        bool $ipcOutsideStatutoryYear = false,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        string $strategy = 'annual_variation',
    ): RentAdjustment {
        return $this->mediator->record(
            rental: $rental,
            newRent: $newRent,
            effectiveFrom: $effectiveFrom,
            ipcYear: $ipcYear,
            notes: $notes,
            ipcOutsideStatutoryYear: $ipcOutsideStatutoryYear,
            basis: $basis,
            geographyCode: $geographyCode,
            pactadoPercentage: $pactadoPercentage,
            strategy: $strategy,
        );
    }

    /**
     * Marca el reajuste como comunicado.
     */
    public function markNotified(
        RentAdjustment $adjustment,
        \DateTimeInterface $notifiedOn,
        ?NotificationMethod $method = null,
    ): RentAdjustment {
        return $this->mediator->markNotified($adjustment, $notifiedOn, $method);
    }

    /**
     * Aplica el canon reajustado al alquiler.
     */
    public function applyToRental(RentAdjustment $adjustment): Rental
    {
        return $this->mediator->applyToRental($adjustment);
    }

    /**
     * Recalcula la cadena completa del alquiler.
     */
    public function recalculateChain(Rental $rental): array
    {
        return $this->mediator->recalculateChain($rental);
    }

    /**
     * El canon vigente del alquiler segun su cadena de reajustes.
     */
    public function currentRentFor(Rental $rental): float
    {
        return $this->mediator->currentRentFor($rental);
    }

    /**
     * Que IPC corresponde para reajustar en una fecha dada.
     */
    public function previewIpc(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        string $strategy = 'annual_variation',
        ?int $ipcYear = null,
    ): IpcSnapshot {
        return $this->mediator->previewIpc(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            basis: $basis,
            geographyCode: $geographyCode,
            pactadoPercentage: $pactadoPercentage,
            strategy: $strategy,
            ipcYear: $ipcYear,
        );
    }

    /**
     * El IPC del articulo 20 sin ningun pacto aplicado.
     */
    public function legalIpcFor(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        ?string $geographyCode = null,
    ): IpcSnapshot {
        return $this->mediator->legalIpcFor($rental, $effectiveFrom, $geographyCode);
    }

    /**
     * Los avisos de la cadena de reglas para una combinacion concreta.
     *
     * @return array{summary: \App\Services\Adjustment\Validation\RuleResult, messages: list<array<string, mixed>>}
     */
    public function validate(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        float $requestedRent,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        bool $acknowledged = false,
        ?string $notes = null,
        ?NotificationMethod $notificationMethod = null,
    ): array {
        return $this->mediator->validate(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            requestedRent: $requestedRent,
            basis: $basis,
            geographyCode: $geographyCode,
            pactadoPercentage: $pactadoPercentage,
            acknowledged: $acknowledged,
            notes: $notes,
            notificationMethod: $notificationMethod,
        );
    }
}
