<?php

namespace App\Enums;

/**
 * Serie del IPC con la que se calcula un reajuste.
 *
 * El articulo 20 dice "el indice de precios al consumidor", sin mas. No menciona
 * ciudades ni ninguna cobertura en particular, y el concepto 119493 del
 * Ministerio de Vivienda lo cita igual. Por eso el total nacional es la base
 * legal y la de ciudad es un dato que el arrendador puede usar, pero apartarse
 * de el es apartarse del articulo 20 y tiene que quedar escrito por que.
 */
enum IpcBasis: string
{
    case National = 'nacional';
    case City = 'ciudad';

    public function label(): string
    {
        return match ($this) {
            self::National => __('ipc.basis.national'),
            self::City => __('ipc.basis.city'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $basis): array => [$basis->value => $basis->label()])
            ->all();
    }

    /**
     * El total nacional es el que fija el articulo 20.
     */
    public function isStatutory(): bool
    {
        return $this === self::National;
    }

    public static function default(): self
    {
        return self::National;
    }
}
