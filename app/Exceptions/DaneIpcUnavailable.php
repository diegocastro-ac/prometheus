<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fallo al hablar con el CDA del DANE.
 *
 * Existe como excepcion propia, y no como un RuntimeException generico, por una
 * razon concreta: hay que poder distinguir "el DANE no respondio" de "el DANE
 * respondio algo que no entiendo". Lo primero se resuelve reintentando y, en el
 * peor caso, conservando la ultima serie buena. Lo segundo significa que el
 * esquema cambio y hay que recapturar la peticion.
 *
 * Sin esa distincion, un cambio de esquema se manifestaria como una app sin IPC
 * y nadie sabria si es una caida de red o que el DANE movio una tabla.
 */
class DaneIpcUnavailable extends RuntimeException
{
    public static function transportFailure(string $url, int $status, ?string $body = null): self
    {
        return new self(sprintf(
            'El DANE respondio HTTP %d a %s.%s',
            $status,
            $url,
            $body === null ? '' : ' Cuerpo: '.mb_substr(trim(strip_tags($body)), 0, 400),
        ));
    }

    public static function unexpectedShape(string $url): self
    {
        return new self(sprintf(
            'La respuesta de %s no tiene la forma de una consulta CDA (faltan metadata o '
            .'resultset). Lo mas probable es que el DANE haya cambiado el archivo .cda o la '
            .'ruta. Revise config/services.php (dane_ipc.cda_path) y recapture la peticion '
            .'desde el tablero.',
            $url,
        ));
    }

    public static function htmlInsteadOfJson(string $url): self
    {
        return new self(sprintf(
            'La respuesta de %s es HTML, no JSON. Suele significar que la ruta del CDA cambio o '
            .'que hubo una redireccion.',
            $url,
        ));
    }
}
