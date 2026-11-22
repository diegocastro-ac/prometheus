<?php

namespace App\Contracts;

use App\Documents\DocumentDescriptor;

/**
 * Registra que un documento fue accedido.
 *
 * El Proxy depende de este contrato y no del modelo: la auditoria puede
 * persistirse en base de datos, enviarse a un log externo o sustituirse en una
 * prueba sin tocar el documento ni la pantalla.
 */
interface DocumentAccessLogger
{
    /**
     * @throws \Throwable si el acceso no puede registrarse
     */
    public function log(DocumentDescriptor $descriptor): void;
}
