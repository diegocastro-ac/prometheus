<?php

namespace App\Commands\Adjustment;

use App\Enums\NotificationMethod;
use App\Models\RentAdjustment;
use RuntimeException;

/**
 * Marca un reajuste como comunicado (patron Command).
 *
 * El receptor es el propio modelo: la constancia de la comunicacion vive en
 * notified_on y notification_method. El comando encapsula la exigencia legal
 * del medio y devuelve el ajuste refrescado.
 */
final class NotifyRentAdjustment implements AdjustmentCommand
{
    public function __construct(
        private readonly RentAdjustment $adjustment,
        private readonly \DateTimeInterface $notifiedOn,
        private readonly ?NotificationMethod $method = null,
    ) {}

    public function execute(): RentAdjustment
    {
        if ($this->method === null) {
            throw new RuntimeException(
                'Debe indicar el medio por el que se comunico el reajuste. El articulo 20 '
                .'exige que la comunicacion se haga por el servicio postal autorizado o por el '
                .'mecanismo pactado en el contrato; sin el medio, la fecha sola no acredita nada.'
            );
        }

        $this->adjustment->update([
            'notified_on' => $this->notifiedOn,
            'notification_method' => $this->method,
        ]);

        return $this->adjustment->refresh();
    }
}
