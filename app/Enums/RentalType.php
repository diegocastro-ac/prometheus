<?php

namespace App\Enums;

/**
 * Destino del inmueble arrendado.
 *
 * El objeto del articulo 1 de la Ley 820 de 2003 es "fijar los criterios que
 * deben servir de base para regular los contratos de arrendamiento de los
 * inmuebles urbanos destinados a vivienda". La ley no aplica a un local
 * comercial: esos contratos se rigen por el Codigo de Comercio y por la Ley
 * 1111 de 2006, donde el canon lo fija el mercado.
 *
 * La distincion importa porque un alquiler comercial no tiene "tope legal" del
 * IPC. Sin este campo la aplicacion le calcularia a un local un limite que
 * ninguna norma le impone, y presentaria como teto legal algo que es una simple
 * referencia de mercado.
 */
enum RentalType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Residential => __('rental.types.residential'),
            self::Commercial => __('rental.types.commercial'),
        };
    }

    /**
     * Devuelve las opciones para un selector de Filament.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * Solo la vivienda urbana esta sometida a la Ley 820.
     */
    public function isSubjectToLey820(): bool
    {
        return $this === self::Residential;
    }

    public static function default(): self
    {
        return self::Residential;
    }
}
