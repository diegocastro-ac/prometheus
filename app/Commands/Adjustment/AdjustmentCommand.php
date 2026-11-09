<?php

namespace App\Commands\Adjustment;

/**
 * Contrato de las acciones discretas sobre un reajuste (patron Command).
 *
 * Cada comando encapsula una peticion completa: el receptor que la ejecuta y
 * los parametros que necesita. El invocador no conoce los detalles de la
 * operacion, solo pide execute().
 */
interface AdjustmentCommand
{
    public function execute(): mixed;
}
