<?php

namespace App\Services\Ipc;

use InvalidArgumentException;

/**
 * Un valor destinado a aparecer en una cadena de SQL, construido solo si es seguro.
 *
 * El servicio CDA del DANE ejecuta literalmente el SQL que recibe. Eso convierte
 * cualquier concatenacion en una inyeccion. La guia original lo advertia y
 * proponia "validar o castear", que es una recomendacion: depende de que
 * alguien la cumpla cada vez.
 *
 * Esta clase lo convierte en una garantia del sistema de tipos. El constructor
 * rechaza el valor si no cumple su regla, y el SQL solo puede armarse a partir
 * de instancias que ya existen. No hay forma de pasar texto de usuario al
 * CDA: no por disciplina, sino porque el objeto que lo haria no se puede
 * construir.
 *
 * Cada tipo de dato tiene su propia regla porque cada uno tiene su propio
 * ataque posible:
 *
 *   GeographyCode  cinco digitos exactos; el total nacional es la excepcion.
 *   Year           entero en un rango con sentido.
 *   Months         lista de enteros de 1 a 12.
 *   Identifier     lista de enteros positivos.
 */
final readonly class SqlScalar
{
    private function __construct(
        public string $literal,
        public string $raw,
    ) {}

    /**
     * Un codigo de geografia del CDA.
     *
     * Solo admite cinco digitos, o el codigo del total nacional, que el DANE
     * publica como "50" y no sigue la convencion DIVIPOLA. Rechaza cualquier
     * otra cosa, incluidos los codigos con signo, con espacios o con una
     * comilla intercalada.
     */
    public static function geographyCode(string $code): self
    {
        $trimmed = trim($code);

        if ($trimmed === \App\Dto\Geography::nationalCode()) {
            return new self(literal: self::quote($trimmed), raw: $trimmed);
        }

        if (preg_match('/^\d{5}$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El codigo de geografia "%s" no tiene cinco digitos. Un DIVIPOLA como '
                .'el de Valledupar es 20001, y el total nacional es %s.',
                $trimmed,
                \App\Dto\Geography::nationalCode(),
            ));
        }

        return new self(literal: self::quote($trimmed), raw: $trimmed);
    }

    /**
     * Un ano calendario dentro de un rango con sentido historico.
     *
     * El limite inferior coincide con el primer ano que publica el CDA y el
     * superior llega un poco mas alla del presente, para que la sincronizacion
     * de enero del ano entrante no falle por estar en el limite.
     */
    public static function year(int $year): self
    {
        $current = (int) now()->year;

        if ($year < 1900 || $year > $current + 1) {
            throw new InvalidArgumentException(sprintf(
                'El ano %d esta fuera del rango admitido (1900-%d).',
                $year,
                $current + 1,
            ));
        }

        return new self(literal: (string) $year, raw: (string) $year);
    }

    /**
     * Un rango de anos cerrado, como 1954-2026.
     */
    public static function yearRange(int $from, int $to): self
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $years = range($from, $to);

        return new self(
            literal: implode(',', array_map(self::year(...), $years)),
            raw: (string) $from.'-'.$to,
        );
    }

    /**
     * Los doce meses del ano.
     *
     * El CDA tiene TT.TIE_MES en numero de mes, del 1 al 12. No necesita filtro:
     * la serie completa son todos los meses existentes.
     */
    public static function allMonths(): self
    {
        return new self(literal: implode(',', range(1, 12)), raw: '1-12');
    }

    /**
     * Una lista de enteros positivos, usada para identificadores del esquema.
     *
     * @param  list<int|string>  $values
     */
    public static function identifiers(array $values): self
    {
        $literals = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if (preg_match('/^\d{1,9}$/', $text) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'El identificador "%s" no es un entero positivo.',
                    $text,
                ));
            }

            $literals[] = $text;
        }

        return new self(literal: implode(',', $literals), raw: implode(',', $literals));
    }

    /**
     * Una lista de cadenas cortas de la propia configuracion, entrecomilladas.
     *
     * Se usa para los codigos de nivel de la canasta, que son enteros en la
     * fuente pero que el tablero envia entrecomillados. Se valida igual: nada de
     * comillas dobles dentro del valor.
     *
     * @param  list<string>  $values
     */
    public static function quotedIdentifiers(array $values): self
    {
        $literals = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if (preg_match('/^\d{1,9}$/', $text) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'El valor de esquema "%s" no es un entero.',
                    $text,
                ));
            }

            $literals[] = self::quote($text);
        }

        return new self(literal: implode(',', $literals), raw: implode(',', $literals));
    }

    public function __toString(): string
    {
        return $this->literal;
    }

    /**
     * Entrecomilla escapando la comilla simple.
     *
     * Es una segunda barrera, no la principal. La principal es que ninguna
     * entrada de usuario llega hasta aqui: todo lo que se entrecomilla sale de
     * la configuracion o de un entero ya validado. El escape existe para que,
     * si alguien anade un valor nuevo sin pasar por la validacion, el SQL no se
     * rompa de forma silenciosa.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
