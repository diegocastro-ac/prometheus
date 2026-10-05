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
        if ($body->document() instanceof \App\Documents\PaymentReceipt) {
            $lines[] = '┌────────────────────────────────────────────────────────────────┐';
            $lines[] = '│ ✓ FACTURA PAGADA COMPLETAMENTE                                   │';
            $lines[] = '└────────────────────────────────────────────────────────────────┘';
            $lines[] = '';
        }

        $sectionNumber = 1;
        $labelWidth = $this->labelWidth($body->fields);

        if ($body->fields !== []) {
            $lines[] = sprintf('%d. DATOS GENERALES', $sectionNumber++);
            $lines[] = str_repeat('─', 60);
            foreach ($body->fields as $label => $value) {
                $lines[] = sprintf('  %-'.($labelWidth).'s : %s', $label.':', $value);
            }
            $lines[] = '';
        }

        if ($body->headers !== [] && $body->rows !== []) {
            $lines[] = sprintf('%d. DETALLE', $sectionNumber++);
            $lines[] = str_repeat('─', 60);
            $lines[] = $this->table($body);
            $lines[] = '';
        }

        if ($body->warnings !== []) {
            $lines[] = sprintf('%d. AVISOS', $sectionNumber++);
            $lines[] = str_repeat('─', 60);
            foreach ($body->warnings as $warning) {
                $lines[] = '  ⚠ '.$warning;
            }
            $lines[] = '';
        }

        if ($body->notes !== []) {
            $lines[] = sprintf('%d. NOTAS', $sectionNumber++);
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
     * @param  list<list<string>>  $rows
     */
    private function table(DocumentBody $body): string
    {
        $widths = $this->widths($body->headers, $body->rows);

        $out = [$this->row($body->headers, $widths)];

        $out[] = implode('  ', array_map(
            static fn (int $width): string => str_repeat('-', $width),
            $widths,
        ));

        foreach ($body->rows as $row) {
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