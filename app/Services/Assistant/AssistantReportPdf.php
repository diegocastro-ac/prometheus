<?php

namespace App\Services\Assistant;

use App\Settings\AppSettings;
use FlexPDF\Facades\Pdf;

/**
 * Renderiza un reporte del copiloto (business_insights / app_guide) a PDF
 * con el mismo diseno de los documentos de la plataforma: cabecera con
 * logo y datos del negocio, pie de pagina numerado y las tablas con el
 * estilo institucional.
 *
 * A diferencia de los documentos del catalogo no nace de una entidad del
 * dominio sino del payload saneado que el chat ya persistio: el metodo
 * recibe el reporte tal cual y la vista decide como pintar cada bloque
 * (metricas, graficas SVG, tablas, pasos, alertas y listas).
 */
class AssistantReportPdf
{
    /**
     * @param  array{title?: string, intro?: string, sections?: list<array<string, mixed>>}  $report
     */
    public function render(array $report): string
    {
        return Pdf::view('documents.assistant-report', [
            'report' => $report,
            'settings' => app(AppSettings::class),
        ])
            ->page('a4')
            ->output();
    }
}
