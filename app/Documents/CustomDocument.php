<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use Illuminate\Support\Str;

/**
 * Documento compuesto por la IA (intent custom_document del asistente).
 *
 * A diferencia de los cuatro documentos del catalogo, este no nace de una
 * entidad del dominio: nace de una especificacion estructurada que el
 * modelo redacto investigando datos reales (titulo, introduccion,
 * campos y secciones con texto, campos y tablas).
 *
 * La especificacion llega desde los params de la accion persistida, asi
 * que se sanea aqui en la frontera: topes de tamano por campo y de
 * secciones/filas para que un spec corrupto no infle el documento ni
 * rompa el renderizador. Ningun contenido se inventa en esta clase:
 * solo se normaliza lo que el modelo ya produjo con datos reales.
 */
class CustomDocument extends AbstractDocument
{
    private const MAX_SECTIONS = 8;

    private const MAX_TABLE_ROWS = 60;

    private const MAX_TABLE_COLS = 10;

    private const MAX_FIELDS = 12;

    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    /**
     * @param  array<string, mixed>  $spec  Especificacion del documento:
     *         {title, intro, fields: {etiqueta: valor}, sections: [{title,
     *         text, fields, table: {headers, rows}}]}
     */
    public static function fromSpec(array $spec, DocumentRenderer $renderer): self
    {
        $sanitized = self::sanitize($spec);

        $sections = $sanitized['sections'];

        // La introduccion encabeza el documento como una seccion sin
        // titulo: es el marco que el modelo escribio para el lector.
        if ($sanitized['intro'] !== '') {
            array_unshift($sections, ['title' => '', 'text' => $sanitized['intro']]);
        }

        $document = new self(
            body: DocumentBody::make($sanitized['title'])
                ->withFields($sanitized['fields'])
                ->withSections($sections)
                ->withFooter(__('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i')),
            renderer: $renderer,
            slug: $sanitized['slug'],
        );

        $document->attachToBody();

        return $document;
    }

    public function preview(): string
    {
        $count = count($this->body->sections);

        return $this->body->title.' — '.($count === 1
            ? __('assistant.doc_sections.one')
            : __('assistant.doc_sections.many', ['count' => $count]));
    }

    /**
     * Un documento personalizado sin secciones ni campos no tiene
     * contenido de negocio: el executor lo reporta como fallo en vez de
     * entregar una hoja vacia.
     */
    public function isEmpty(): bool
    {
        return $this->body->sections === [] && $this->body->fields === [];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{title: string, intro: string, fields: array<string, string>, sections: list<array<string, mixed>>, slug: string}
     */
    private static function sanitize(array $spec): array
    {
        $title = self::text($spec['title'] ?? '', 140);

        if ($title === '') {
            $title = __('assistant.doc_types.custom');
        }

        return [
            'title' => $title,
            'intro' => self::text($spec['intro'] ?? '', 2000),
            'fields' => self::fields($spec['fields'] ?? []),
            'sections' => self::sections($spec['sections'] ?? []),
            'slug' => self::slug($title),
        ];
    }

    /**
     * @return list<array{title: string, text?: string, fields?: array<string, string>, table?: array{headers: list<string>, rows: list<list<string>>}}>
     */
    private static function sections(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $sections = [];

        foreach (array_slice($raw, 0, self::MAX_SECTIONS) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $clean = [
                'title' => self::text($section['title'] ?? '', 100),
                'text' => self::text($section['text'] ?? '', 3000),
            ];

            $fields = self::fields($section['fields'] ?? []);
            $table = self::table($section['table'] ?? null);

            if ($fields !== []) {
                $clean['fields'] = $fields;
            }

            if ($table !== null) {
                $clean['table'] = $table;
            }

            // Una seccion sin titulo, texto, campos ni tabla no aporta
            // nada al documento.
            if ($clean['title'] === '' && $clean['text'] === '' && $fields === [] && $table === null) {
                continue;
            }

            $sections[] = $clean;
        }

        return $sections;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}|null
     */
    private static function table(mixed $raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['headers']) || ! is_array($raw['headers']) || ! isset($raw['rows']) || ! is_array($raw['rows'])) {
            return null;
        }

        $headers = [];

        foreach (array_slice($raw['headers'], 0, self::MAX_TABLE_COLS) as $header) {
            $headers[] = self::text($header, 60);
        }

        if ($headers === []) {
            return null;
        }

        $rows = [];

        foreach (array_slice($raw['rows'], 0, self::MAX_TABLE_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = array_map(
                fn (mixed $cell): string => self::text($cell, 300),
                array_slice(array_values($row), 0, count($headers)),
            );
        }

        return $rows === [] ? null : ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array<string, string>
     */
    private static function fields(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $fields = [];

        foreach ($raw as $label => $value) {
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }

            $label = self::text($label, 60);
            $value = self::text($value, 300);

            if ($label === '' || $value === '') {
                continue;
            }

            $fields[$label] = $value;
        }

        return $fields;
    }

    private static function text(mixed $value, int $max): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private static function slug(string $title): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'documento';
    }
}
