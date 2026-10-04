<?php

namespace App\Documents\Rendering;

use Barryvdh\DomPDF\Facade\Pdf;

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
        return Pdf::loadView('documents.document', ['body' => $body])
            ->setPaper('letter')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->output();
    }
}