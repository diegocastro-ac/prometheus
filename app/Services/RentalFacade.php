<?php

namespace App\Services;

use App\Documents\Contracts\Document;
use App\Documents\DocumentService;
use App\Dto\IpcSnapshot;
use App\Enums\BillingCadence;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use App\Services\Adjustment\AdjustmentDateProposer;
use Illuminate\Support\Carbon;

/**
 * Punto de entrada unico a las operaciones del alquiler.
 *
 * Las pantallas que administran alquileres, facturas, reajustes y documentos
 * hablan con esta clase en lugar de resolver cada servicio por su cuenta. La
 * fachada no decide nada: conserva la regla donde estaba y solo coordina las
 * dependencias para que el consumidor vea una sola puerta.
 *
 * En particular, no absorbe los accesos auxiliares del formulario de reajustes
 * (IpcSeriesRepository, AppSettings) ni mueve las notificaciones: esas siguen en
 * los servicios y pantallas que ya las tenian.
 */
class RentalFacade
{
    public function __construct(
        private readonly PaymentPlanService $paymentPlans,
        private readonly PaymentRecordingService $payments,
        private readonly RentAdjustmentService $adjustments,
        private readonly DocumentService $documents,
        private readonly AdjustmentChainCalculator $chain,
        private readonly AdjustmentChainRecalculator $recalculator,
        private readonly AdjustmentDateProposer $dates,
    ) {}

    public function generatePaymentPlan(Rental $rental, ?BillingCadence $cadence = null): void
    {
        $this->paymentPlans->generateFor($rental, $cadence);
    }

    /**
     * @return array{payment: \App\Models\Payment, invoice: Invoice, just_settled: bool}
     */
    public function recordPayment(
        Invoice $invoice,
        float $amount,
        ?string $method = null,
        ?string $reference = null,
        ?Carbon $paidAt = null,
        ?string $receiptImage = null,
    ): array {
        return $this->payments->record(
            invoice: $invoice,
            amount: $amount,
            method: $method,
            reference: $reference,
            paidAt: $paidAt,
            receiptImage: $receiptImage,
        );
    }

    public function recordAdjustment(
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
        return $this->adjustments->record(
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

    public function markAdjustmentNotified(
        RentAdjustment $adjustment,
        \DateTimeInterface $notifiedOn,
        ?NotificationMethod $method = null,
    ): RentAdjustment {
        return $this->adjustments->markNotified($adjustment, $notifiedOn, $method);
    }

    public function applyAdjustment(RentAdjustment $adjustment): Rental
    {
        return $this->adjustments->applyToRental($adjustment);
    }

    /**
     * @return array{touched: int, current_rent: float, changed_ids: list<int>}
     */
    public function recalculateAdjustmentChain(Rental $rental): array
    {
        return $this->recalculator->recalculate($rental);
    }

    public function currentRentFor(Rental $rental): float
    {
        return $this->chain->currentRentFor($rental);
    }

    public function previewAdjustmentIpc(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        string $strategy = 'annual_variation',
        ?int $ipcYear = null,
    ): IpcSnapshot {
        return $this->adjustments->previewIpc(
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
     * @return array{summary: \App\Services\Adjustment\Validation\RuleResult, messages: list<array<string, mixed>>}
     */
    public function validateAdjustment(
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
        return $this->adjustments->validate(
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

    public function suggestedAdjustmentDate(\DateTimeInterface $date): \DateTimeInterface
    {
        return $this->dates->suggestedDate($date);
    }

    public function adjustmentDateExplanation(
        \DateTimeInterface $legal,
        \DateTimeInterface $suggested,
    ): string {
        return $this->dates->explanation($legal, $suggested);
    }

    public function receiptFor(Invoice $invoice, ?string $format = null): Document
    {
        return $this->documents->receiptFor($invoice, $format);
    }

    public function invoiceFor(Invoice $invoice, ?string $format = null): Document
    {
        return $this->documents->invoiceFor($invoice, $format);
    }

    public function adjustmentLetterFor(RentAdjustment $adjustment, ?string $format = null): Document
    {
        return $this->documents->adjustmentLetterFor($adjustment, $format);
    }

    public function statementFor(Rental $rental, string $period, ?string $format = null): Document
    {
        return $this->documents->statementFor($rental, $period, $format);
    }

    /**
     * @return array<string, string>
     */
    public function documentFormats(): array
    {
        return $this->documents->formatOptions();
    }
}
