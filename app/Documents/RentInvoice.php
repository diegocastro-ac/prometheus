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
        // Cargar relaciones para evitar N+1
        $invoice->load(['rental', 'user', 'payments' => fn($q) => $q->orderBy('date')]);

        $body = DocumentBody::make(__('document.invoice'))
            ->withFields([
                __('document.invoice_number') => $invoice->number,
                __('document.concept') => $invoice->conceptLabel(),
                __('document.rental') => $invoice->rental?->name ?? 'no indicado',
                __('document.period') => $invoice->period ?? 'no indicado',
                __('document.issued_date') => $invoice->issued_at->format('d/m/Y'),
                __('document.due_date') => $invoice->due_at->format('d/m/Y'),
                __('document.status') => __('invoice.statuses.'.$invoice->status->value),
                __('document.total_due') => Money::exact($invoice->amount),
            ]);

        if ($invoice->hasPartialPayment()) {
            $body = $body->withTable(
                [__('document.date'), __('document.amount_paid'), __('document.method'), __('document.reference')],
                $invoice->payments->map(fn ($payment): array => [
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
            ->withDocument($invoice)
            ->withNotes(
                __('invoice.descriptions.legal'),
            )
            ->withFooter(
                __('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}