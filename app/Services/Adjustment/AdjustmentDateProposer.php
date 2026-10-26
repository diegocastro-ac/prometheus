<?php

namespace App\Services\Adjustment;

use App\Contracts\BusinessCalendar;

/**
 * Propone la fecha de vigencia de un reajuste.
 *
 * POR QUE EXISTE, Y POR QUE NO MUEVE LA FECHA LEGAL
 * -----------------------------------------------
 * El articulo 20 dice "cada doce (12) meses de ejecucion del contrato bajo un
 * mismo precio". El plazo lo cumple una FECHA: el aniversario del ultimo cambio de
 * canon. Moverla seria cambiar el computo legal, y este servicio no lo hace nunca.
 *
 * Lo que si es un problema real es otro, y es practico. El mismo articulo, en su
 * parrafo 2, obliga a comunicar "el monto del incremento y la fecha en que se
 * hara efectivo, a traves del servicio postal autorizado". El correo autorizado no
 * entrega los domingos ni en dias festivos. Una vigencia que cae en domingo deja
 * al arrendador en una paradoja: la fecha legal ya vencio y el aviso no pudo
 * hacerse.
 *
 * La salida es la de todos los plazos en Colombia: si el vencimiento cae en dia no
 * habil, se entiende al siguiente dia habil. Eso no mueve el plazo, cambia
 * la fecha en que se puede cumplir.
 *
 * Por eso este servicio devuelve DOS fechas y deja claro cual es cual:
 *
 *   legalDate()      el aniversario. Es el que corre el plazo del articulo 20.
 *   suggestedDate()  el dia habil siguiente, que es el que se propone en pantalla.
 *
 * ASI SE USA EL ADAPTER
 * ---------------------
 * Reutiliza el BusinessCalendar que ya existe para el vencimiento de facturas. No
 * se duplico ni se movio: se inyecta el mismo contrato, de modo que las dos
 * funcionalidades comparten la misma fuente de festivos y en las pruebas siguen
 * siendo deterministas con el calendario de fin de semana.
 */
class AdjustmentDateProposer
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * El dia habil siguiente a una fecha.
     *
     * nextBusinessDay() ya lo resuelve en el adaptador, incluido el salto de fines
     * de semana largos y festivos seguidos. Aqui solo se le pide que no mueva una
     * fecha que ya era habil.
     */
    public function suggestedDate(\DateTimeInterface $date): \DateTimeInterface
    {
        return $this->calendar->nextBusinessDay(\Carbon\Carbon::instance($date)->startOfDay());
    }

    public function isBusinessDay(\DateTimeInterface $date): bool
    {
        return $this->calendar->isBusinessDay(\Carbon\Carbon::instance($date));
    }

    /**
     * Si la fecha propuesta se movio respecto de la legal.
     *
     * Cuando es falso, la fecha propuesta ES la fecha legal y no hay nada que
     * explicarle a nadie.
     */
    public function wasMoved(\DateTimeInterface $legal, \DateTimeInterface $suggested): bool
    {
        return $legal->format('Y-m-d') !== $suggested->format('Y-m-d');
    }

    /**
     * El texto que explica por que se propone una fecha y no la otra.
     *
     * Devuelve cadena vacia cuando la fecha no se movio, para que la pantalla solo
     * muestre la nota cuando hay algo que decir.
     */
    public function explanation(\DateTimeInterface $legal, \DateTimeInterface $suggested): string
    {
        if (! $this->wasMoved($legal, $suggested)) {
            return '';
        }

        return __('rent_adjustment.form.business_day_note', [
            'legal' => \Carbon\Carbon::instance($legal)->format('d/m/Y'),
            'suggested' => \Carbon\Carbon::instance($suggested)->format('d/m/Y'),
        ]);
    }
}
