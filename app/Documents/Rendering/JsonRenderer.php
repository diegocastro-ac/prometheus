<?php

namespace App\Documents\Rendering;

/**
 * Renderizador JSON.
 *
 * Es el tercer ConcreteImplementor del Bridge. Recibe el mismo DocumentBody
 * que el PDF y el texto, pero lo entrega como una estructura estable para que
 * un sistema contable pueda importarlo sin interpretar una plantilla visual.
 *
 * No incluye meta: ahi viaja la imagen del comprobante como data URI, y un
 * archivo contable no debe cargar una imagen embebida. Si hace falta la
 * referencia, se agrega como dato aparte en otra fase.
 */
class JsonRenderer implements DocumentRenderer
{
    public function mimeType(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function deliveryLabel(): string
    {
        return __('invoice.documents.download_json');
    }

    public function isDownload(): bool
    {
        return true;
    }

    public function render(DocumentBody $body): string
    {
        $document = $body->document();

        return json_encode([
            'title' => $body->title,
            'document_type' => $document === null ? null : class_basename($document),
            'fields' => $body->fields,
            'table' => $body->headers === [] ? null : [
                'headers' => $body->headers,
                'rows' => $body->rows,
            ],
            'warnings' => $body->warnings,
            'notes' => $body->notes,
            'footer' => $body->footer,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
