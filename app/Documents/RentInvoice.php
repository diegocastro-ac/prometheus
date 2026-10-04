<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use App\Models\Invoice;

/**
 * Factura de arrendamiento.
 *
 * A diferencia del comprobante, esta si existe para facturas sin pagar: es el
 * documento que se entrega para cobrar. Por eso no lleva la restriccion de
 * estado que tiene PaymentReceipt.
 */
class RentInvoice extends AbstractDocument
{
    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
        private readonly Invoice $invoice,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    public static function forInvoice(Invoice $invoice, DocumentRenderer $renderer): self
    {
        return new self(
            body: self::buildBody($invoice),
            renderer: $renderer,
            slug: 'factura-'.$invoice->number,
            invoice: $invoice,
        );
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function preview(): string
    {
        return 'Factura '.$this->invoice->number.' por '
            .Money::exact($this->invoice->amount)
            .', estado '.$this->invoice->status->value;
    }

    private static function buildBody(Invoice $invoice): DocumentBody
    {
        $body = DocumentBody::make('Factura de arrendamiento')
            ->withFields([
                'Numero' => $invoice->number,
                'Concepto' => $invoice->conceptLabel(),
                'Alquiler' => $invoice->rental?->name ?? 'no indicado',
                'Periodo' => $invoice->period ?? 'no indicado',
                'Emision' => $invoice->issued_at->format('d/m/Y'),
                'Vence' => $invoice->due_at->format('d/m/Y'),
                'Estado' => __('invoice.statuses.'.$invoice->status->value),
                'Total' => Money::exact($invoice->amount),
            ]);

        if ($invoice->hasPartialPayment()) {
            $body = $body->withTable(
                ['Fecha', 'Abono', 'Forma de pago', 'Referencia'],
                $invoice->payments()->orderBy('date')->get()
                    ->map(fn ($payment): array => [
                        $payment->date->format('d/m/Y'),
                        Money::exact($payment->amount),
                        $payment->methodLabel(),
                        $payment->reference ?? '-',
                    ])->all(),
            );
        }

        if ($invoice->notes) {
            $body = $body->withNotes($invoice->notes);
        }

        return $body
            ->withNotes(
                __('invoice.descriptions.legal'),
            )
            ->withFooter(
                'Documento generado por Prometheus el '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}