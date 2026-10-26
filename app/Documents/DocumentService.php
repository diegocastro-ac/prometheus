<?php

namespace App\Documents;

use App\Contracts\DocumentAccessLogger;
use App\Documents\Contracts\Document;
use App\Documents\Factories\DocumentFactory;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;
use Closure;

/**
 * Punto de entrada de los cuatro documentos.
 *
 * Existe para que la pantalla no tenga que conocer la fabrica ni el formato.
 * La pantalla pide "el comprobante de esta factura" y este servicio decide
 * primero cual es la familia y despues prepara un Proxy que difiere la
 * construccion y audita el acceso al contenido.
 *
 * Todas las consultas de facturas pasan por las relaciones del modelo en lugar
 * de por consultas sueltas aqui, para que el alcance del usuario se aplique
 * siempre y no dependa de recordar un where.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentFactoryLocator $locator,
        private readonly DocumentAccessLogger $accessLogger,
    ) {}

    /**
     * Comprobante de pago de una factura.
     *
     * La validacion se hace aqui y no dentro del Proxy para que una factura sin
     * pagar falle de inmediato, antes de que exista una entrega diferida.
     *
     * @throws \DomainException si la factura no esta pagada
     */
    public function receiptFor(Invoice $invoice, ?string $format = null): Document
    {
        PaymentReceipt::ensureInvoiceIsPaid($invoice);

        $factory = $this->factory($format);

        return $this->proxy(
            type: 'payment_receipt',
            format: $factory->format(),
            reference: $invoice->number,
            context: ['invoice_id' => $invoice->id],
            builder: fn (): Document => $factory->paymentReceipt($invoice),
        );
    }

    /**
     * Factura de arrendamiento.
     */
    public function invoiceFor(Invoice $invoice, ?string $format = null): Document
    {
        $factory = $this->factory($format);

        return $this->proxy(
            type: 'rent_invoice',
            format: $factory->format(),
            reference: $invoice->number,
            context: ['invoice_id' => $invoice->id],
            builder: fn (): Document => $factory->rentInvoice($invoice),
        );
    }

    /**
     * Carta de reajuste.
     */
    public function adjustmentLetterFor(RentAdjustment $adjustment, ?string $format = null): Document
    {
        $factory = $this->factory($format);

        return $this->proxy(
            type: 'adjustment_letter',
            format: $factory->format(),
            reference: 'REAJUSTE-'.$adjustment->id,
            context: [
                'rent_adjustment_id' => $adjustment->id,
                'rental_id' => $adjustment->rental_id,
            ],
            builder: fn (): Document => $factory->adjustmentLetter($adjustment),
        );
    }

    /**
     * Estado de cuenta de un alquiler en un periodo.
     *
     * Las facturas se piden al alquiler con la relacion ya filtrada, y el
     * documento las recibe en una lista. La consulta queda dentro del closure
     * para que solo se pague si el contenido se necesita de verdad.
     */
    public function statementFor(Rental $rental, string $period, ?string $format = null): Document
    {
        $factory = $this->factory($format);

        return $this->proxy(
            type: 'monthly_statement',
            format: $factory->format(),
            reference: 'ALQUILER-'.$rental->id.' · '.$period,
            context: [
                'rental_id' => $rental->id,
                'period' => $period,
            ],
            builder: fn (): Document => $factory->monthlyStatement(
                $period,
                array_values($rental->invoicesForPeriod($period)->get()->all()),
            ),
        );
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
            'json' => __('invoice.documents.format.json'),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure(): Document  $builder
     */
    private function proxy(
        string $type,
        string $format,
        string $reference,
        array $context,
        Closure $builder,
    ): Document {
        $userId = auth()->id();

        return new DocumentProxy(
            descriptor: new DocumentDescriptor(
                type: $type,
                format: $format,
                userId: $userId !== null ? (int) $userId : null,
                reference: $reference,
                context: $context,
            ),
            builder: $builder,
            logger: $this->accessLogger,
        );
    }

    private function factory(?string $format): DocumentFactory
    {
        return $this->locator->for($format);
    }
}
