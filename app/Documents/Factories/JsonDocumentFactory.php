<?php

namespace App\Documents\Factories;

use App\Documents\AdjustmentLetter;
use App\Documents\MonthlyStatement;
use App\Documents\PaymentReceipt;
use App\Documents\Rendering\JsonRenderer;
use App\Documents\RentInvoice;
use App\Models\Invoice;
use App\Models\RentAdjustment;

/**
 * Familia de documentos en JSON.
 *
 * Replica la familia de texto y la de PDF con un unico cambio: el renderizador
 * que se inyecta. Los cuatro productos siguen armando el mismo DocumentBody y
 * las mismas reglas de negocio; solo cambia la serializacion final.
 */
class JsonDocumentFactory implements DocumentFactory
{
    public function __construct(
        private readonly JsonRenderer $renderer,
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
        return 'json';
    }
}
