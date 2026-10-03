<?php

namespace App\Enums;

enum ActItemState: string
{
    case NUEVO = 'nuevo';
    case BUENO = 'bueno';
    case REPARABLE = 'reparable';
    case POR_REEMPLAZAR = 'por_reemplazar';
    case DESTRUIDO = 'destruido';

    public function label(): string
    {
        return __('act.item_states.'.$this->value);
    }

    /**
     * Un elemento en mal estado obliga a dejar nota. El Builder no lo impone:
     * la regla vive en el formulario, aqui solo se expone el criterio.
     */
    public function needsNote(): bool
    {
        return in_array($this, [self::REPARABLE, self::POR_REEMPLAZAR, self::DESTRUIDO], true);
    }
}