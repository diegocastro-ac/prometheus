<?php

namespace App\Documents;

/**
 * Formato de moneda para los documentos.
 *
 * Los cuatro documentos muestran cantidades y todos deben escribir igual un
 * mismo valor. Centralizarlo aqui evita que el comprobante diga "800000" y la
 * carta de reajuste "800.000", que es la clase de diferencia que hace dudar de
 * un documento ante el arrendatario.
 */
final class Money
{
    /**
     * Un canon no se pacta con centavos: el IPC se aplica sobre el valor
     * redondo y mostrar decimales sugeriria una precision que no existe.
     */
    public static function pesos(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }

    /**
     * Montos que si pueden llevar centavos, como un abono parcial.
     */
    public static function exact(float $amount): string
    {
        return '$'.number_format($amount, 2, ',', '.');
    }

    /**
     * Porcentaje con el signo explicito. El IPC se expresa positivo ("9,28 %")
     * pero un incremento negativo debe verse como "-3,00 %" y no como
     * "3,00 %" a secas.
     */
    public static function percent(float $percentage): string
    {
        $sign = $percentage > 0 ? '+' : '';

        return $sign.number_format($percentage, 2, ',', '.').' %';
    }

    public static function percentPlain(float $percentage): string
    {
        return number_format($percentage, 2, ',', '.').' %';
    }
}