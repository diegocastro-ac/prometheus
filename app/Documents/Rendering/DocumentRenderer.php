<?php

namespace App\Documents\Rendering;

/**
 * Convierte un DocumentBody en la entrega final de una familia concreta.
 *
 * Cada familia de productos del patron (PDF, texto) tiene su propio
 * renderizador. Las implementaciones son intercambiables: el documento recibe
 * cualquiera de las dos y su comportamiento no cambia, solo su presentacion.
 */
interface DocumentRenderer
{
    public function mimeType(): string;

    /**
     * Extension del archivo, sin punto.
     */
    public function extension(): string;

    /**
     * Como se entrega el documento en este formato. El PDF se descarga; el
     * texto se copia al portapapeles porque descargar un .txt no aporta nada
     * en una notificacion.
     */
    public function deliveryLabel(): string;

    /**
     * Si esta familia entrega un archivo o un texto para mostrar en pantalla.
     */
    public function isDownload(): bool;

    public function render(DocumentBody $body): string;
}