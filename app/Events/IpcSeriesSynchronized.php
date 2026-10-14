<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se publico una serie nueva del IPC.
 *
 * Es el punto de enganche del patron Observer. Lo dispara el comando de
 * sincronizacion y a partir de ahi los suscriptores decide que hacer, sin que el
 * comando tenga que saber de ellos.
 *
 * Lo que se avisa es la cadena completa, no la nacional. Cuando el DANE corrige
 * un dato, hay que rehacer lo mismo para las ciudades: un reajuste que se calculo
 * con una cifra que el DANE corrige tiene que poder recalcularse, y sin este
 * aviso quedaria desalineado de la copia local.
 */
class IpcSeriesSynchronized
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $geographies  las que se sincronizaron con exito.
     */
    public function __construct(
        public readonly array $geographies,
        public readonly int $storedPeriods,
        public readonly bool $nationalOnly = false,
    ) {}
}
