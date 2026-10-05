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

        // Obtener la imagen del comprobante del último pago
        $receiptImage = null;
        $lastPayment = $invoice->payments->last();
        if ($lastPayment && $lastPayment->receipt_image) {
            $imagePath = public_path('storage/' . $lastPayment->receipt_image);
            if (file_exists($imagePath)) {
                // Convertir a data URI (base64)
                $imageData = base64_encode(file_get_contents($imagePath));
                $mimeType = mime_content_type($imagePath);
                $receiptImage = 'data:' . $mimeType . ';base64,' . $imageData;
            }
        }

        $body = DocumentBody::make(__('document.payment_receipt'))
            ->withFields([
                __('document.invoice_number') => $invoice->number,
                __('document.concept') => $invoice->conceptLabel(),
                __('document.period') => $invoice->period ?? 'no indicado',
                __('document.rental') => $invoice->rental?->name ?? 'no indicado',
                __('document.issued_date') => $invoice->issued_at->format('d/m/Y'),
                __('document.payment_date') => $invoice->paid_at?->format('d/m/Y') ?? 'sin fecha registrada',
                __('document.invoice_amount') => Money::exact($invoice->amount),
                __('document.paid_amount') => Money::exact($invoice->paidAmount()),
                __('document.balance') => Money::exact($invoice->balance()),
            ]);

        if ($invoice->payments->isNotEmpty()) {
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

        return $body
            ->withDocument($invoice)
            ->withMeta(['receipt_image' => $receiptImage])
            ->withNotes(
                __('document.receipt_note').' '.$invoice->number
                .' '.__('document.receipt_note_suffix'),
            )
            ->withFooter(
                __('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}