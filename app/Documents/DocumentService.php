<?php

namespace App\Documents;

use App\Documents\Factories\DocumentFactory;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;

/**
 * Fachada de los cuatro documentos.
 *
 * Existe para que la pantalla no tenga que conocer la fabrica ni el formato.
 * La pantalla pide "el comprobante de esta factura" y este servicio decide
 * primero cual es la familia y despues arma el documento. Esa unica decision de
 * formato por peticion es la que evita mezclar productos incompatibles.
 *
 * Todas las consultas de facturas pasan por las relaciones del modelo en lugar
 * de por consultas sueltas aqui, para que el alcance del usuario se aplique
 * siempre y no dependa de recordar un where.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentFactoryLocator $locator,
    ) {}

    /**
     * Comprobante de pago de una factura.
     *
     * @throws \DomainException si la factura no esta pagada
     */
    public function receiptFor(Invoice $invoice, ?string $format = null): PaymentReceipt
    {
        return $this->factory($format)->paymentReceipt($invoice);
    }

    /**
     * Factura de arrendamiento.
     */
    public function invoiceFor(Invoice $invoice, ?string $format = null): RentInvoice
    {
        return $this->factory($format)->rentInvoice($invoice);
    }

    /**
     * Carta de reajuste.
     */
    public function adjustmentLetterFor(RentAdjustment $adjustment, ?string $format = null): AdjustmentLetter
    {
        return $this->factory($format)->adjustmentLetter($adjustment);
    }

    /**
     * Estado de cuenta de un alquiler en un periodo.
     *
     * Las facturas se piden al alquiler con la relacion ya filtrada, y el
     * documento las recibe en una lista. Asi el documento no consulta la base de
     * datos y se puede probar sin ella.
     */
    public function statementFor(Rental $rental, string $period, ?string $format = null): MonthlyStatement
    {
        $invoices = $rental->invoicesForPeriod($period)->get()->all();

        return $this->factory($format)->monthlyStatement($period, array_values($invoices));
    }

    /**
     * Formatos disponibles, para el selector de la pantalla.
     *
     * @return array<string, string>
     */
    public function formatOptions(): array
    {
        return [
            'text' => __('invoice.documents.format.text'),
            'pdf' => __('invoice.documents.format.pdf'),
        ];
    }

    private function factory(?string $format): DocumentFactory
    {
        return $this->locator->for($format);
    }
}