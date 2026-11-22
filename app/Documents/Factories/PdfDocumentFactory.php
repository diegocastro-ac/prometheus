<?php

namespace App\Documents\Factories;

use App\Documents\AdjustmentLetter;
use App\Documents\MonthlyStatement;
use App\Documents\PaymentReceipt;
use App\Documents\Rendering\PdfRenderer;
use App\Documents\RentInvoice;
use App\Models\Invoice;
use App\Models\RentAdjustment;

/**
 * Familia de documentos en PDF.
 *
 * Comparte con la familia de texto los mismos cuatro productos y las mismas
 * reglas de negocio. Lo unico que cambia es el renderizador que se inyecta, y
 * eso lo decide la fabrica: los documentos no saben en que formato estan.
 */
class PdfDocumentFactory implements DocumentFactory
{
    public function __construct(
        private readonly PdfRenderer $renderer,
    ) {}

    public function paymentReceipt(Invoice $invoice): PaymentReceipt
    {
        return PaymentReceipt::forInvoice($invoice, $this->renderer);
    }

    public function adjustmentLetter(RentAdjustment $adjustment): AdjustmentLetter
    {
        return AdjustmentLetter::forAdjustment($adjustment, $this->renderer);
    }

    /**
     * @param  list<Invoice>  $invoices
     */
    public function monthlyStatement(string $period, array $invoices): MonthlyStatement
    {
        return MonthlyStatement::forPeriod($period, $invoices, $this->renderer);
    }

    public function rentInvoice(Invoice $invoice): RentInvoice
    {
        return RentInvoice::forInvoice($invoice, $this->renderer);
    }

    public function format(): string
    {
        return 'pdf';
    }
}