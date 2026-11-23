<?php

namespace App\Documents\Rendering;

/**
 * Renderizador de texto plano.
 *
 * Se usa para el boton de copiar y para GenerateDocumentText que expone el
 * contenido por HTTP. La plantilla de texto no es una version recortada del
 * PDF: trae los mismos datos, alineados con espacios porque el ancho de
 * columna en un visor de texto es irregular.
 */
class PlainTextRenderer implements DocumentRenderer
{
    /**
     * Ancho maximo de columna en el texto plano. Un texto plano no tiene
     * paginas ni margenes, asi que el ancho lo elegimos nosotros para que la
     * tabla siga siendo legible en cualquier terminal.
     */
    private const COLUMN_MAX = 46;

    public function mimeType(): string
    {
        return 'text/plain; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'txt';
    }

    public function deliveryLabel(): string
    {
        return __('invoice.documents.copy');
    }

    /**
     * El texto se muestra y se copia. Descargar un .txt obliga a abrir un
     * archivo temporal, que es un paso extra sin ninguna ganancia.
     */
    public function isDownload(): bool
    {
        return false;
    }

    public function render(DocumentBody $body): string
    {
        $lines = [];
        $title = $body->title;
        $titleLength = mb_strlen($title);
        $border = str_repeat('═', $titleLength + 4);

        $lines[] = '╔'.$border.'╗';
        $lines[] = '║  '.str_pad($title, $titleLength).'  ║';
        $lines[] = '╚'.$border.'╝';
        $lines[] = '';

        // Header con datos del negocio si es comprobante
        /*
        | Sin simbolos dentro de la caja.
        |
        | Antes este recuadro llevaba un visto bueno y las advertencias un signo de
        | advertencia. Los dos son el mismo error por motivos distintos: en texto
        | plano monoespaciado no todos los terminals ni todas las fuentes tienen el
        | glifo, y cuando falta se sustituye por un cuadrado. En un comprobante
        | que va impreso o pegado en un correo, un cuadrado al lado de "PAGADO" es
        | inaceptable.
        |
        | El recuadro ya comunica la misma informacion por su forma, y el texto
        | de la advertencia ya dice que es una advertencia.
        */
        if ($body->document() instanceof \App\Documents\PaymentReceipt) {
            $lines[] = '┌────────────────────────────────────────────────────────────────┐';
            $lines[] = '│   '.str_pad(__('document.paid_in_full'), 59).'│';
            $lines[] = '└────────────────────────────────────────────────────────────────┘';
            $lines[] = '';
        }

        $sectionNumber = 1;
        $labelWidth = $this->labelWidth($body->fields);

        if ($body->fields !== []) {
            $lines[] = sprintf('%d. %s', $sectionNumber++, __('document.general_data'));
            $lines[] = str_repeat('─', 60);
            foreach ($body->fields as $label => $value) {
                $lines[] = sprintf('  %-'.($labelWidth).'s : %s', $label.':', $value);
            }
            $lines[] = '';
        }

        if ($body->headers !== [] && $body->rows !== []) {
            $lines[] = sprintf('%d. %s', $sectionNumber++, __('document.details'));
            $lines[] = str_repeat('─', 60);
            $lines[] = $this->table($body->headers, $body->rows);
            $lines[] = '';
        }

        // Secciones de documentos compuestos: cada una puede llevar
        // titulo, texto, campos y su propia tabla.
        foreach ($body->sections as $section) {
            if (($section['title'] ?? '') !== '') {
                $lines[] = sprintf('%d. %s', $sectionNumber++, $section['title']);
            }

            if (($section['text'] ?? '') !== '') {
                $lines[] = '  '.str_replace("\n", "\n  ", $section['text']);
            }

            $sectionFields = is_array($section['fields'] ?? null) ? $section['fields'] : [];

            if ($sectionFields !== []) {
                $sectionLabelWidth = $this->labelWidth($sectionFields);

                foreach ($sectionFields as $label => $value) {
                    $lines[] = sprintf('  %-'.($sectionLabelWidth).'s : %s', $label.':', $value);
                }
            }

            $sectionTable = $section['table'] ?? null;

            if (is_array($sectionTable) && isset($sectionTable['headers'], $sectionTable['rows'])) {
                $lines[] = '';
                $lines[] = $this->table($sectionTable['headers'], $sectionTable['rows']);
            }

            if (($section['title'] ?? '') !== '' || ($section['text'] ?? '') !== '' || $sectionFields !== [] || isset($sectionTable)) {
                $lines[] = '';
            }
        }

        if ($body->warnings !== []) {
            $lines[] = sprintf('%d. %s', $sectionNumber++, __('document.warnings'));
            $lines[] = str_repeat('─', 60);
            foreach ($body->warnings as $warning) {
                $lines[] = '  - '.$warning;
            }
            $lines[] = '';
        }

        if ($body->notes !== []) {
            $lines[] = sprintf('%d. %s', $sectionNumber++, __('document.notes'));
            $lines[] = str_repeat('─', 60);
            foreach ($body->notes as $note) {
                $lines[] = '  '.$note;
            }
            $lines[] = '';
        }

        if ($body->footer !== null) {
            $lines[] = '╔'.str_repeat('═', 72).'╗';
            $lines[] = '║  '.$body->footer.'  ║';
            $lines[] = '╚'.str_repeat('═', 72).'╝';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        $widths = $this->widths($headers, $rows);

        $out = [$this->row($headers, $widths)];

        $out[] = implode('  ', array_map(
            static fn (int $width): string => str_repeat('-', $width),
            $widths,
        ));

        foreach ($rows as $row) {
            $out[] = $this->row($row, $widths);
        }

        return implode(PHP_EOL, $out);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<int>
     */
    private function widths(array $headers, array $rows): array
    {
        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }

        return array_map(
            static fn (int $width): int => min($width, self::COLUMN_MAX),
            $widths,
        );
    }

    /**
     * @param  list<string>  $cells
     * @param  list<int>  $widths
     */
    private function row(array $cells, array $widths): string
    {
        $padded = [];

        foreach ($cells as $index => $cell) {
            $width = $widths[$index] ?? mb_strlen($cell);
            $padded[] = mb_strlen($cell) > $width
                ? mb_substr($cell, 0, $width - 1).'…'
                : str_pad($cell, $width);
        }

        return rtrim(implode('  ', $padded));
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function labelWidth(array $fields): int
    {
        $width = 0;

        foreach (array_keys($fields) as $label) {
            $width = max($width, mb_strlen((string) $label));
        }

        return $width;
    }
}
