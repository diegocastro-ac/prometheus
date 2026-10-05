<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use DomainException;

/**
 * Comprobante de pago de una factura.
 *
 * Solo existe para facturas pagadas. El bloqueo va en el dominio y no solo
 * escondiendo el boton: una factura emitida o vencida no tiene comprobante que
 * entregar, y si se pide uno la peticion falla. Dejar el boton en gris no
 * alcanza, porque el enlace se puede invocar igual.
 *
 * El constructor es privado y el unico camino es forInvoice(), que valida. Asi
 * es imposible tener en memoria un comprobante de una factura sin pagar.
 */
class PaymentReceipt extends AbstractDocument
{
    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
        private readonly Invoice $invoice,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    /**
     * @throws DomainException si la factura no esta pagada
     */
    public static function forInvoice(Invoice $invoice, DocumentRenderer $renderer): self
    {
        if ($invoice->status !== InvoiceStatus::PAGADA) {
            throw new DomainException(
                'La factura '.$invoice->number.' esta en estado '.$invoice->status->value
                .'. El comprobante solo existe para facturas pagadas.'
            );
        }

        return new self(
            body: self::buildBody($invoice),
            renderer: $renderer,
            slug: 'comprobante-'.$invoice->number,
            invoice: $invoice,
        );
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function preview(): string
    {
        return 'Comprobante de pago de la factura '.$this->invoice->number
            .' por '.Money::exact($this->invoice->amount);
    }

    private static function buildBody(Invoice $invoice): DocumentBody
    {
        // Cargar relaciones para evitar N+1
        $invoice->load(['rental', 'user', 'payments' => fn($q) => $q->orderBy('date')->orderBy('id')]);

        $body = DocumentBody::make('Comprobante de pago')
            ->withFields([
                'Factura' => $invoice->number,
                'Concepto' => $invoice->conceptLabel(),
                'Periodo' => $invoice->period ?? 'no indicado',
                'Alquiler' => $invoice->rental?->name ?? 'no indicado',
                'Emision' => $invoice->issued_at->format('d/m/Y'),
                'Pago' => $invoice->paid_at?->format('d/m/Y') ?? 'sin fecha registrada',
                'Total facturado' => Money::exact($invoice->amount),
                'Total abonado' => Money::exact($invoice->paidAmount()),
                'Saldo' => Money::exact($invoice->balance()),
            ]);

        if ($invoice->payments->isNotEmpty()) {
            $body = $body->withTable(
                ['Fecha', 'Abono', 'Forma de pago', 'Referencia'],
                $invoice->payments->map(fn ($payment): array => [
                    $payment->date->format('d/m/Y'),
                    Money::exact($payment->amount),
                    $payment->methodLabel(),
                    $payment->reference ?? '-',
                ])->all(),
            );
        }

        return $body
            ->withDocument($invoice)
            ->withNotes(
                'Este comprobante acredita el pago de la factura '.$invoice->number
                .' y no sustituye la factura electronica de venta.',
            )
            ->withFooter(
                'Documento generado por Prometheus el '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}