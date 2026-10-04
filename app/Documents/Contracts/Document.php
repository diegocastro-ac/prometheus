<?php

namespace App\Documents\Contracts;

/**
 * Un documento ya armado, listo para entregarse.
 *
 * No sabe de que fabrica salio. Eso es lo que permite que la pantalla de
 * descarga no tenga que preguntar por el formato: pide el documento y ejecuta
 * lo que el documento ofrezca.
 */
interface Document
{
    /**
     * Nombre del archivo que se descarga, con su extension.
     */
    public function filename(): string;

    /**
     * Tipo MIME con el que se entrega.
     */
    public function mimeType(): string;

    /**
     * Etiqueta del boton de entrega. El PDF dice "Descargar" y el texto
     * "Copiar": lo decide la familia, no la pantalla.
     */
    public function deliveryLabel(): string;

    /**
     * Si el documento se entrega como archivo o se muestra en pantalla.
     *
     * Vive en el documento y no en un `if ($format === 'pdf')` de la pantalla
     * porque comparar cadenas de etiquetas o de formatos es fragile: el
     * documento declara como se entrega y la pantalla solo obedece.
     */
    public function isDownload(): bool;

    /**
     * El contenido final, listo para escribir o para copiar.
     */
    public function content(): string;

    /**
     * Texto breve para identificar el documento en un listado y para leer sin
     * abrirlo.
     */
    public function preview(): string;
}