<?php

namespace App\Documents;

use App\Contracts\DocumentAccessLogger;
use App\Documents\Contracts\Document;
use Closure;

/**
 * Proxy de proteccion de los documentos.
 *
 * La pantalla y las acciones reciben este objeto en lugar del documento real.
 * La metadata se delega sin registrar nada; el contenido pasa primero por la
 * auditoria y solo despues se renderiza el documento.
 *
 * La construccion es perezosa y se memoriza: el primer metodo delegado, aunque
 * sea de metadata, arma el documento real. La auditoria solo se dispara desde
 * content(), y un mismo contenido no se audita dos veces aunque Filament evalue
 * el modal varias veces.
 */
final class DocumentProxy implements Document
{
    private ?Document $document = null;

    private bool $audited = false;

    /**
     * @param  Closure(): Document  $builder
     */
    public function __construct(
        private readonly DocumentDescriptor $descriptor,
        private readonly Closure $builder,
        private readonly DocumentAccessLogger $logger,
    ) {}

    /**
     * Un fallo de auditoria detiene la entrega: es preferible no generar el
     * documento a generar uno cuyo acceso no quedo registrado.
     */
    public function content(): string
    {
        $this->audit();

        return $this->document()->content();
    }

    public function filename(): string
    {
        return $this->document()->filename();
    }

    public function mimeType(): string
    {
        return $this->document()->mimeType();
    }

    public function deliveryLabel(): string
    {
        return $this->document()->deliveryLabel();
    }

    public function isDownload(): bool
    {
        return $this->document()->isDownload();
    }

    public function isEmpty(): bool
    {
        return $this->document()->isEmpty();
    }

    public function preview(): string
    {
        return $this->document()->preview();
    }

    private function audit(): void
    {
        if ($this->audited) {
            return;
        }

        $this->logger->log($this->descriptor);

        // Se marca despues del log: si la auditoria falla, un segundo intento
        // vuelve a intentarla y el contenido sigue bloqueado.
        $this->audited = true;
    }

    private function document(): Document
    {
        return $this->document ??= ($this->builder)();
    }
}
