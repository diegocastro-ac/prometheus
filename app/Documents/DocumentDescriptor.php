<?php

namespace App\Documents;

/**
 * Datos que identifican un acceso a documento para la auditoria.
 *
 * No contiene el documento real ni su contenido. El Proxy lo crea antes de
 * construir el documento, de modo que el registro pueda hacerse justo antes de
 * renderizar y sin cargar relaciones que quiza no lleguen a usarse.
 */
final readonly class DocumentDescriptor
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $type,
        public string $format,
        public ?int $userId,
        public ?string $reference = null,
        public array $context = [],
    ) {}
}
