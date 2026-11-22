<?php

namespace App\Enums;

enum BillingCadence: string
{
    case MENSUAL = 'MENSUAL';
    case QUINCENAL = 'QUINCENAL';
    case ANTICIPO = 'ANTICIPO';

    public function label(): string
    {
        return __('billing_cadence.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $cadence): array => [$cadence->value => $cadence->label()])
            ->all();
    }
}
