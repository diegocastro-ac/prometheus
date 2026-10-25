<?php

namespace App\Documents;

use App\Documents\Contracts\Document as DocumentContract;
use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;

/**
 * Base de los cuatro documentos.
 *
 * Todos reciben un DocumentBody ya armado y un DocumentRenderer. El cuerpo dice
 * que se documenta; el renderizador decide en que formato se entrega. Por eso
 * el documento no tiene un "si soy PDF entonces..." branching propio: eso lo
 * haria conocer las dos familias y perderia el punto del patron.
 *
 * El renderizado se memoriza. Un PDF es costoso de generar y content() puede
 * invocarse varias veces dentro de una misma respuesta HTTP, asi que se
 * resuelve una vez y se reutiliza.
 */
abstract class AbstractDocument implements DocumentContract
{
    private ?string $rendered = null;

    /**
     * @param  string  $slug  Nombre sin extension; el renderizador aporta la
     *                        suya, asi que el documento no conoce el formato.
     */
    public function __construct(
        protected DocumentBody $body,
        private readonly DocumentRenderer $renderer,
        private readonly string $slug,
    ) {}

    /**
     * El cuerpo sin formato. Lo consultan los tests para verificar el dato sin
     * depender de que dompdf este disponible.
     */
    public function body(): DocumentBody
    {
        return $this->body;
    }

    /**
     * Adjunta este documento al cuerpo que se va a renderizar.
     *
     * El cuerpo se arma antes de que la instancia exista, asi que la referencia
     * se cierra aqui y no en cada fabrica. Los renderizadores la usan para
     * elegir una plantilla o para mostrar bloques propios de un documento.
     */
    protected function attachToBody(): void
    {
        $this->body = $this->body->withDocument($this);
    }

    public function filename(): string
    {
        return $this->slug.'.'.$this->renderer->extension();
    }

    public function mimeType(): string
    {
        return $this->renderer->mimeType();
    }

    public function deliveryLabel(): string
    {
        return $this->renderer->deliveryLabel();
    }

    public function isDownload(): bool
    {
        return $this->renderer->isDownload();
    }

    public function content(): string
    {
        return $this->rendered ??= $this->renderer->render($this->body);
    }
}