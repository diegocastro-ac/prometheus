<?php

namespace App\Dto;

/**
 * Una geografia sobre la que el DANE publica IPC.
 *
 * El codigo es el PAI_ID del CDA y se guarda siempre como texto de cinco
 * caracteres. El CDA lo devuelve como numero, y al perder el cero inicial
 * "08001" llega como 8001 y "05001" como 5001. Convertirlos a entero no
 * devuelve el error que uno esperaria: no falla, devuelve otra ciudad.
 *
 * El catalogo se arma con qryCiudad, que devuelve 23 ciudades con IPC. Esa
 * lista es el unico lugar del que salen los codigos; nada en la aplicacion
 * asume que un DIVIPOLA cualquiera tenga IPC.
 */
final readonly class Geography
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $department = null,
        public bool $hasIpc = true,
        public string $source = 'cda',
    ) {}

    /**
     * El codigo con el que el CDA publica el total nacional.
     *
     * No es un DIVIPOLA: el CDA lo usa como "50" y filtrar por "00050" no
     * devuelve filas. Por eso el nacional travela tal cual y solo las ciudades
     * se rellenan a cinco digitos.
     */
    public static function nationalCode(): string
    {
        return (string) config('dane_ipc_sql.national_code', '50');
    }

    /**
     * Normaliza un PAI_ID del CDA.
     *
     * Las ciudades se rellenan a cinco digitos con cero a la izquierda, que es
     * la operacion que evita el error mas caro del modulo: sin ella, "8001" y
     * "08001" son dos ciudades distintas en la base de datos, y el filtro
     * escribiria un codigo que el CDA no reconoce sin avisar.
     *
     * El total nacional se deja intacto porque su codigo no sigue esa regla.
     */
    public static function normaliseCode(int|string|null $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $code = preg_replace('/\D/', '', (string) $raw);

        if ($code === null || $code === '') {
            return null;
        }

        if ($code === self::nationalCode()) {
            return $code;
        }

        return str_pad(substr($code, 0, 5), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  iterable<array-key, mixed>  $rows
     * @return list<self>
     */
    public static function fromRawRows(iterable $rows, string $source = 'cda'): array
    {
        $geographies = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = self::normaliseCode($row['code'] ?? null);
            $name = trim((string) ($row['name'] ?? ''));

            if ($code === null || $name === '') {
                continue;
            }

            $geographies[$code] = new self(
                code: $code,
                name: $name,
                department: self::normaliseDepartment($row['department'] ?? null),
                hasIpc: true,
                source: $source,
            );
        }

        return array_values($geographies);
    }

    /**
     * @return array<string, string>
     */
    public function toOption(): array
    {
        return [$this->code => $this->label()];
    }

    /**
     * El total nacional no pertenece a un departamento.
     */
    public function isNational(): bool
    {
        return $this->code === self::nationalCode();
    }

    public function label(): string
    {
        return $this->department
            ? $this->name.' — '.$this->department
            : $this->name;
    }

    private static function normaliseDepartment(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $department = trim((string) $raw);

        return $department === '' ? null : $department;
    }
}
