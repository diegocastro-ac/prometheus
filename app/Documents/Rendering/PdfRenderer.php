<?php

namespace App\Documents\Rendering;

use App\Documents\AdjustmentLetter;
use App\Documents\MonthlyStatement;
use App\Documents\PaymentReceipt;
use App\Documents\RentInvoice;
use App\Settings\AppSettings;
use FlexPDF\Facades\Pdf;

/**
 * Renderizador en PDF.
 *
 * DomPDF traduce la hoja de estilos del proyecto a PDF, asi que la plantilla
 * Blade no necesita declarar estilos: hereda los de resources/css. Lo que si
 * hace falta es el ajuste de papel y margenes, que es una decision de la
 * familia PDF y no del documento.
 */
class PdfRenderer implements DocumentRenderer
{
    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function deliveryLabel(): string
    {
        return __('invoice.documents.download');
    }

    /**
     * El PDF no se muestra en linea ni en una celda de tabla ni en una
     * notificacion: siempre se descarga.
     */
    public function isDownload(): bool
    {
        return true;
    }

    public function render(DocumentBody $body): string
    {
        $settings = app(AppSettings::class);
        $document = $body->document();

        // Seleccionar plantilla según tipo de documento
        $view = $this->selectView($document);

        return Pdf::view($view, [
            'body' => $body,
            'document' => $document,
            'settings' => $settings,
        ])
        ->page('a4')
        ->output();
    }

    private function selectView(?object $document): string
    {
        if ($document === null) {
            return 'documents.document';
        }

        return match($document::class) {
            RentInvoice::class => 'documents.invoice',
            PaymentReceipt::class => 'documents.receipt',
            AdjustmentLetter::class => 'documents.adjustment-letter',
            MonthlyStatement::class => 'documents.monthly-statement',
            default => 'documents.document',
        };
    }
}