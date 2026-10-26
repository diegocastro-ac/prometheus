<?php

namespace App\Documents\Factories;

use App\Documents\AdjustmentLetter;
use App\Documents\MonthlyStatement;
use App\Documents\PaymentReceipt;
use App\Documents\Rendering\PlainTextRenderer;
use App\Documents\RentInvoice;
use App\Models\Invoice;
use App\Models\RentAdjustment;

/**
 * Familia de documentos en texto plano.
 *
 * Es la implementacion que se inyecta por defecto. El texto plano es el
 * formato menos exigente porque no depende de dompdf ni de fuentes, asi que los
 * tests y la vista previa pueden usarlo siempre.
 */
class TextDocumentFactory implements DocumentFactory
{
    public function __construct(
        private readonly PlainTextRenderer $renderer,
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
        return 'text';
    }
}