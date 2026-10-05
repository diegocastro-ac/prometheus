<?php

namespace App\Documents\Rendering;

/**
 * El contenido de un documento, todavia sin formato.
 *
 * Es la pieza que hace posible el Abstract Factory: un documento describe que
 * dice, y la familia (PDF o texto) decide como se ve. Sin este objeto
 * intermedio habria que reescribir cada documento dos veces, una por formato,
 * y las dos copias terminarian discrepando.
 *
 * Los campos son un mapa de etiqueta => valor. Se elige un mapa y no una lista
 * de pares porque una etiqueta no puede repetirse en el mismo bloque: dos
 * "Total" en el mismo documento serian ambiguos, y con un mapa la segunda
 * sobreescribe a la primera en vez de duplicar la linea.
 */
final class DocumentBody
{
    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $title,
        public readonly array $fields = [],
        public readonly array $headers = [],
        public readonly array $rows = [],
        public readonly array $notes = [],
        public readonly array $warnings = [],
        public readonly ?string $footer = null,
        private ?object $document = null,
    ) {}

    /**
     * @param  array<string, string>  $fields
     */
    public static function make(string $title, array $fields = []): self
    {
        return new self(title: $title, fields: $fields);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function withFields(array $fields): self
    {
        return $this->clone(fields: array_merge($this->fields, $fields));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function withTable(array $headers, array $rows): self
    {
        return $this->clone(headers: $headers, rows: $rows);
    }

    public function withNotes(string ...$notes): self
    {
        return $this->clone(notes: [...$this->notes, ...$notes]);
    }

    /**
     * Avisos que el documento debe mostrar de forma destacada.
     *
     * Van aparte de las notas porque no son informacion del hecho economico
     * sino una advertencia legal: que el incremento supera el IPC y que solo
     * opera con acuerdo escrito. Se separan para que ningun formato pueda
     * omitirlos por descuido.
     */
    public function withWarning(string ...$warnings): self
    {
        return $this->clone(warnings: [...$this->warnings, ...$warnings]);
    }

    public function withFooter(?string $footer): self
    {
        return $this->clone(footer: $footer);
    }

    public function withDocument(object $document): self
    {
        return $this->clone(document: $document);
    }

    public function document(): ?object
    {
        return $this->document;
    }

    private function clone(
        ?array $fields = null,
        ?array $headers = null,
        ?array $rows = null,
        ?array $notes = null,
        ?array $warnings = null,
        ?string $footer = null,
        ?object $document = null,
    ): self {
        return new self(
            title: $this->title,
            fields: $fields ?? $this->fields,
            headers: $headers ?? $this->headers,
            rows: $rows ?? $this->rows,
            notes: $notes ?? $this->notes,
            warnings: $warnings ?? $this->warnings,
            footer: $footer ?? $this->footer,
            document: $document ?? $this->document,
        );
    }
}