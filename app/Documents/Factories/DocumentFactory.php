<?php

namespace App\Documents\Factories;

use App\Documents\AdjustmentLetter;
use App\Documents\MonthlyStatement;
use App\Documents\PaymentReceipt;
use App\Documents\RentInvoice;
use App\Models\Invoice;
use App\Models\RentAdjustment;

/**
 * Abstract Factory: la fabrica de documentos.
 *
 * La interfaz declara los cuatro productos que la aplicacion sabe construir, y
 * cada implementacion entrega una familia completa en un unico formato. Quien
 * pide documentos llama siempre a esta interfaz y no a una familia concreta:
 *
 * - TextDocumentFactory entrega los cuatro como texto plano.
 * - PdfDocumentFactory entrega los cuatro como PDF.
 *
 * Las dos familias publican los mismos metodos con el mismo nombre, y esa es
 * justamente la garantia que ofrece el patron: no se puede pedir "el PDF del
 * comprobante" ni "el texto del estado de cuenta". O se elige una familia para
 * todo el documento, o no se elige ninguna.
 *
 * Este es el motivo por el que la aplicacion no recibe "pdf" o "txt" como
 * parametro suelto en cada llamada: con un parametro seria posible combinar
 * productos incompatibles en un mismo documento, que es justo lo que el patron
 * excluye.
 */
interface DocumentFactory
{
    /**
     * Comprobante de pago. Falla si la factura no esta pagada.
     */
    public function paymentReceipt(Invoice $invoice): PaymentReceipt;

    /**
     * Carta de comunicacion de reajuste del canon.
     */
    public function adjustmentLetter(RentAdjustment $adjustment): AdjustmentLetter;

    /**
     * Estado de cuenta de un periodo, con todas sus facturas.
     *
     * @param  list<Invoice>  $invoices
     */
    public function monthlyStatement(string $period, array $invoices): MonthlyStatement;

    /**
     * Factura de arrendamiento.
     */
    public function rentInvoice(Invoice $invoice): RentInvoice;

    /**
     * El formato que entrega esta familia. Sirve para etiquetar la interfaz y
     * para que los tests affirmen que una fabrica produce la familia completa.
     */
    public function format(): string;
}