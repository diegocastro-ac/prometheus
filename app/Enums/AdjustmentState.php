<?php

namespace App\Enums;

/**
 * Estado del reajuste dentro de su ciclo de vida (patron State).
 *
 * Los tres estados no son etiquetas: cada uno habilita unicamente lo que le
 * corresponde, y el paso al siguiente tiene una condicion. Un reajuste
 * notificado que no se aplico no puede ser la base del siguiente, porque el
 * articulo 20 cuenta los doce meses "bajo un mismo precio" y el precio solo
 * cambia cuando el incremento entra en vigor.
 *
 * El estado no se guarda en base de datos: se deduce de las fechas. Guardarlo
 * como columna seria una segunda fuente de verdad que puede contradecir a las
 * fechas, que son las que tienen valor probatorio.
 */
enum AdjustmentState: string
{
    /** Registrado, todavia no comunicado al arrendatario. */
    case Recorded = 'recorded';

    /** Comunicado con fecha y medio. A partir de aqui el incremento es oponible. */
    case Notified = 'notified';

    /** El canon nuevo quedo vigente en el alquiler. */
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => __('rent_adjustment.states.recorded'),
            self::Notified => __('rent_adjustment.states.notified'),
            self::Applied => __('rent_adjustment.states.applied'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }

    /**
     * Un reajuste solo cuenta para la cadena de calculo cuando ya esta aplicado.
     *
     * Si un ajuste quedo registrado pero sin aplicar, el canon del alquiler no
     * cambio y encadenarlo haria que el siguiente se calculara sobre una cifra
     * que nunca rigio.
     */
    public function countsTowardsChain(): bool
    {
        return $this === self::Applied;
    }

    public function canNotify(): bool
    {
        return $this === self::Recorded;
    }

    public function canApply(): bool
    {
        return $this === self::Notified;
    }

    public function canEdit(): bool
    {
        return $this !== self::Applied;
    }

    /**
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return [
            self::Recorded->value => 'gray',
            self::Notified->value => 'info',
            self::Applied->value => 'success',
        ];
    }

    /**
     * El color del badge en las tablas de Filament.
     */
    public function color(): string
    {
        return self::colors()[$this->value] ?? 'gray';
    }
}
