<?php

namespace App\Filament\Support;

use App\Settings\AppSettings;

/**
 * La tabla del cálculo del reajuste.
 *
 * POR QUÉ ES UNA TABLA Y NO UNA TARJETA
 * ------------------------------------
 * Porque lo que responde es una cuenta, no un dato suelto. La pregunta que surge
 * al ver el campo «Canon reajustado» es *¿de dónde sale este número?*, y su
 * respuesta es una cadena: canon vigente, más un porcentaje, da un incremento, y
 * el canon vigente más el incremento da el tope. Esa cadena solo se lee en
 * vertical, y con una columna de importes alineados a la derecha que se sigue
 * con la vista.
 *
 * POR QUÉ TIENE UNA TERCERA COLUMNA
 * ---------------------------------
 * Porque sin ella las cifras son correctas pero no comprobables. Ver «5,10 %»
 * sin saber de qué año y de qué ciudad es un número sin contexto; ver «IPC
 * aplicado 5,10 % (2025, total nacional)» sí se puede contrastar. Esa columna es
 * la que convierte la tabla en una explicación.
 *
 * POR QUÉ LOS TOTALES VAN EN TFOOT
 * ---------------------------------
 * Porque no es lo mismo una línea del desglose que una conclusión. El tope del
 * artículo 20 y el canon que se va a registrar son el resultado de todo lo de
 * arriba, y separarlos visualmente dice eso sin tener que decirlo.
 *
 * RESPONSIVE SIN JavaScript
 * -------------------------
 * En una pantalla estrecha las tres columnas no caben. La solución es que la
 * columna de aclaración baje debajo del concepto usando CSS: en escritorio son
 * tres columnas, en móvil son dos, y el texto pequeño va debajo. Sin script y
 * sin decidir el ancho en PHP, que es donde suele acabarse la responsive.
 */
final class AdjustmentCalculationTable
{
    public function __construct(
        private readonly AdjustmentCalculation $calculation,
    ) {}

    public static function make(AdjustmentCalculation $calculation): self
    {
        return new self($calculation);
    }

    public function render(): string
    {
        $c = $this->calculation;

        $body = $this->row(
            __('rent_adjustment.preview.current_rent'),
            $this->money($c->currentRent),
            __('rent_adjustment.preview.current_rent_hint'),
        )
            .$this->row(
                __('rent_adjustment.preview.applied_ipc'),
                number_format($c->percentage, 2, ',', '.').' %',
                __('rent_adjustment.preview.applied_ipc_value', [
                    'year' => $c->year,
                    'geography' => $c->geographyName,
                ]),
            )
            .$this->row(
                __('rent_adjustment.preview.increase'),
                $this->money($c->increase),
                null,
            );

        $foot = $this->total(
            __('rent_adjustment.preview.cap'),
            $this->money($c->cap),
            __('rent_adjustment.preview.cap_hint'),
        );

        // El canon que se registra solo aparece cuando ya se escribió. Antes de
        // eso es el mismo tope, y mostrarlo dos veces como dos filas distintas
        // haría pensar que son dos cifras.
        if ($c->requestedRent !== null) {
            $foot .= $this->total(
                __('rent_adjustment.preview.selected_rent'),
                $this->money($c->requestedRent),
                null,
                'ajuste-preview__total--chosen',
            );

            $difference = $c->differenceFromCap();

            if (abs($difference) >= 1) {
                $above = $difference > 0;

                $foot .= $this->row(
                    $above
                        ? __('rent_adjustment.preview.above_cap')
                        : __('rent_adjustment.preview.below_cap'),
                    ($above ? '+' : '−').$this->money(abs($difference)),
                    $above ? __('rent_adjustment.preview.above_cap_hint') : null,
                    $above ? 'ajuste-preview__row--excess' : 'ajuste-preview__row--under',
                );
            }
        }

        /*
        | La clase fi-ta-table es la que usa Filament en todas sus tablas. Al
        | ponerla aquí la vista previa hereda el look base del panel —ancho
        | completo, separadores entre filas, espaciado y modo oscuro— sin volver
        | a escribirlo. Las clases ajuste-preview__* solo añaden lo específico de
        | esta cuenta: los totales, los hints y el alineado de los importes.
        |
        | El título ya no va en un <caption>: lo pone el heading de la sección
        | que envuelve esta tabla, para que sea una sección de formulario como
        | las demás y no un bloque suelto entre campos.
        */
        return '<table class="ajuste-preview fi-ta-table w-full text-start">'
            .'<tbody>'.$body.'</tbody>'
            .'<tfoot>'.$foot.'</tfoot>'
            .'</table>';
    }

    /**
     * Una línea del desglose: concepto, importe y aclaración.
     */
    private function row(string $label, string $value, ?string $hint, string $modifier = ''): string
    {
        return '<tr class="'.($modifier === '' ? '' : $modifier).'">'
            .'<th scope="row" class="ajuste-preview__label">'
            .'<span class="ajuste-preview__label-text">'.e($label).'</span>'
            .($hint === null ? '' : '<span class="ajuste-preview__hint">'.e($hint).'</span>')
            .'</th>'
            .'<td class="ajuste-preview__value">'.e($value).'</td>'
            .'</tr>';
    }

    /**
     * Una línea de total: visualmente separada del desglose.
     */
    private function total(string $label, string $value, ?string $hint, string $modifier = ''): string
    {
        return '<tr class="ajuste-preview__total '.($modifier === '' ? '' : $modifier).'">'
            .'<th scope="row" class="ajuste-preview__label">'
            .'<span class="ajuste-preview__label-text">'.e($label).'</span>'
            .($hint === null ? '' : '<span class="ajuste-preview__hint">'.e($hint).'</span>')
            .'</th>'
            .'<td class="ajuste-preview__value">'.e($value).'</td>'
            .'</tr>';
    }

    private function money(float $amount): string
    {
        return app(AppSettings::class)->currency().' '.number_format($amount, 0, ',', '.');
    }
}
