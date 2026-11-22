<?php

namespace App\Infrastructure\Ipc;

use App\Contracts\Ipc\IpcProvider;
use App\Dto\Geography;
use App\Dto\IpcObservation;
use App\Exceptions\DaneIpcUnavailable;
use App\Services\Ipc\CdaQueryFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador del IPC del DANE sobre el servicio CDA de Pentaho.
 *
 * El DANE no publica una API REST del IPC. Lo que alimenta su "Consulta dinamica
 * del IPC" es un motor de SQL embebido al que se le manda la consulta por POST.
 * No hay documentacion, no hay version y los nombres de las tablas internas
 * pueden cambiar sin aviso.
 *
 * Este adapter es la unica parte del sistema que conoce esa realidad. Todo lo
 * que devuelve esta ya normalizado:
 *
 *   - "193,28" pasa a 193.28, porque el DANE usa coma decimal.
 *   - "Sep" pasa a 9, porque el mes llega abreviado.
 *   - 8001 pasa a "08001", porque el CDA entrega el PAI_ID como numero y al
 *     perder el cero el filtro buscaria una ciudad que no existe en silencio.
 *   - las columnas se emparejan por nombre, nunca por posicion, para que anadir
 *     una al final no rompa el parseo.
 *
 * Tres fallos que se encontraron al revisar el sistema y que conviene conocer:
 *
 *   1. La guia original filtraba por los niveles 14 a 18 de la canasta, lo que
 *      devuelve una porcion de la serie y no el IPC. Aqui se pide solo el
 *      nivel 14. El detalle esta en config/dane_ipc_sql.php.
 *   2. El total nacional no se obtiene excluyendo ciudades: es una fila del
 *      propio modelo, con PAI_ID 50.
 *   3. El endpoint responde 502 de forma intermitente. Por eso hay reintentos
 *      con espera creciente, y por eso quien llama debe poder seguir con la
 *      copia local cuando esta excepcion sale.
 */
class DaneCdaIpcProvider implements IpcProvider
{
    /**
     * Meses en abreviatura, tal como los publica el CDA.
     *
     * El mapa es explicito y no se calcula con substr: "Sep" y "Sept" dan tres
     * letras iguales, y el nueve depende de la abreviatura exacta.
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    public function __construct(
        private readonly CdaQueryFactory $queries,
    ) {}

    public function name(): string
    {
        return 'DANE (CDA)';
    }

    /**
     * @return list<IpcObservation>
     */
    public function fetchSeries(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array
    {
        $fields = $this->queries->seriesQuery(
            geographyCode: Geography::normaliseCode($geographyCode) ?? $geographyCode,
            fromYear: $fromYear,
            toYear: $toYear,
        );

        $json = $this->doQuery($fields);
        $columns = $this->columnNames($json);
        $observations = [];

        foreach ($json['resultset'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $record = $this->combine($columns, $row);
            $observation = $this->toObservation($record, $geographyCode);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    /**
     * @return list<Geography>
     */
    public function fetchGeographies(): array
    {
        $sql = config('dane_ipc_sql');
        $dataAccessId = $sql['queries']['cities'];

        $json = $this->doQuery($this->queries->simpleQuery($dataAccessId));
        $columns = $this->columnNames($json);

        $rows = [];

        foreach ($json['resultset'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $record = $this->combine($columns, $row);

            $rows[] = [
                'code' => $record[$sql['columns']['cities']['code']] ?? null,
                'name' => $record[$sql['columns']['cities']['name']] ?? null,
            ];
        }

        return Geography::fromRawRows($rows, source: 'cda');
    }

    /**
     * @return list<int>
     */
    public function fetchAvailableYears(): array
    {
        $sql = config('dane_ipc_sql');
        $json = $this->doQuery($this->queries->simpleQuery($sql['queries']['years']));
        $columns = $this->columnNames($json);
        $key = $sql['columns']['years']['year'];

        $years = [];

        foreach ($json['resultset'] as $row) {
            $record = $this->combine($columns, (array) $row);
            $year = $record[$key] ?? null;

            if ($year !== null && is_numeric($year)) {
                $years[] = (int) $year;
            }
        }

        rsort($years);

        return $years;
    }

    /**
     * La peticion, con la reintentos y la validacion de la forma de la respuesta.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function doQuery(array $fields): array
    {
        $url = rtrim((string) config('services.dane_ipc.base_url'), '/').'/doQuery';
        $attempts = max(1, (int) config('services.dane_ipc.retries', 4));
        $delay = (int) config('services.dane_ipc.retry_delay_ms', 2000);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->send($url, $fields);
            } catch (ConnectionException $exception) {
                // Caida de red o timeout. Es el caso que mas se repite y el
                // que mas merece reintentar.
                $lastError = $exception;
                $response = null;
            }

            if ($response !== null) {
                $validated = $this->validate($response, $url);

                if ($validated !== null) {
                    return $validated;
                }

                $lastError = DaneIpcUnavailable::transportFailure($url, $response->status(), $response->body());
            }

            if ($attempt < $attempts) {
                // Espera creciente. El 502 del DANE suele durar unos segundos
                // y reintentar de inmediato solo suma carga.
                usleep($delay * $attempt * 1000);
            }
        }

        Log::warning('El CDA del DANE no respondio tras varios intentos.', [
            'url' => $url,
            'intentos' => $attempts,
            'error' => $lastError?->getMessage(),
        ]);

        throw $lastError instanceof DaneIpcUnavailable
            ? $lastError
            : new DaneIpcUnavailable(
                'No se pudo consultar el IPC del DANE en '.$attempts.' intentos: '
                .($lastError?->getMessage() ?? 'sin detalle'),
                previous: $lastError,
            );
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function send(string $url, array $fields): Response
    {
        // asForm() es obligatorio: el CDA lee application/x-www-form-urlencoded.
        // Mandar JSON o poner los parametros en la URL produce 405 o HTML.
        return Http::asForm()
            ->timeout((int) config('services.dane_ipc.timeout', 90))
            ->withHeaders(['Accept' => 'application/json'])
            ->post($url, $fields);
    }

    /**
     * Comprueba la forma de la respuesta y devuelve el cuerpo ya decodificado.
     *
     * @return array<string, mixed>|null null si hay que reintentar.
     */
    private function validate(Response $response, string $url): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $json = $response->json();

        if (! is_array($json)) {
            // El CDA devuelve HTML cuando la ruta o el archivo .cda ya no son los
            // que le pedimos. Es un fallo de configuracion, no de red, asi que
            // no tiene sentido reintentar: se lanza de inmediato.
            throw DaneIpcUnavailable::htmlInsteadOfJson($url);
        }

        if (! isset($json['metadata'], $json['resultset'])) {
            // 200 pero con otra forma: el esquema cambio. Tampoco reintento.
            throw DaneIpcUnavailable::unexpectedShape($url);
        }

        return $json;
    }

    /**
     * Los colName de la respuesta, en orden.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function columnNames(array $json): array
    {
        return array_values(array_map(
            static fn (array $column): string => (string) ($column['colName'] ?? ''),
            (array) $json['metadata'],
        ));
    }

    /**
     * Empareja una fila con los nombres de columna.
     *
     * @param  list<string>  $columns
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>
     */
    private function combine(array $columns, array $row): array
    {
        $record = [];

        foreach ($columns as $index => $name) {
            if ($name !== '') {
                $record[$name] = $row[$index] ?? null;
            }
        }

        return $record;
    }

    /**
     * Normaliza una fila del CDA.
     *
     * @param  array<string, mixed>  $record
     */
    private function toObservation(array $record, string $requestedCode): ?IpcObservation
    {
        $year = $this->toInt($record['Año'] ?? null);
        $month = $this->toMonth($record['Mes'] ?? null);
        $index = $this->toFloat($record['Número Índice'] ?? null);

        // Sin ano, mes o indice la fila no sirve para un reajuste: el indice es
        // el unico dato del que se puede derivar cualquier porcentaje.
        if ($year === null || $month === null || $index === null) {
            return null;
        }

        $code = Geography::normaliseCode($record['PAI_ID'] ?? null)
            ?? (Geography::normaliseCode($requestedCode) ?? $requestedCode);

        return new IpcObservation(
            geographyCode: $code,
            geographyName: trim((string) ($record['Ciudad'] ?? '')) ?: (string) config('dane_ipc_sql.national_name', 'NACIONAL'),
            year: $year,
            month: $month,
            index: $index,
            variationMonthly: $this->toFloat($record['Variación Mensual'] ?? null),
            variationYoY: $this->toFloat($record['Variación Anual'] ?? null),
            variationYear: $this->toFloat($record['Variación Año Corrido'] ?? null),
        );
    }

    /**
     * "Sep", "Sept" o "9" a 9.
     */
    private function toMonth(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $month = (int) $raw;

            return $month >= 1 && $month <= 12 ? $month : null;
        }

        $text = mb_strtolower(trim((string) $raw));

        // Se busca el prefijo de tres letras, que es la abreviatura estable
        // entre "Sep" y "Sept".
        foreach (self::MONTHS as $abbreviation => $number) {
            if (str_starts_with($text, $abbreviation)) {
                return $number;
            }
        }

        return null;
    }

    private function toInt(mixed $raw): ?int
    {
        return ($raw === null || $raw === '' || ! is_numeric($raw)) ? null : (int) $raw;
    }

    /**
     * El DANE publica coma decimal, no punto.
     *
     * "193,28" es el caso normal y "193.28" el defensivo: si algun dia el CDA
     * cambia de formato, esta conversion no debe empezar a perder el valor. Con
     * coma, (float) "193,28" da 193, que esta fuera del rango de un indice,
     * asi que se puede detectar y avisar en vez de guardar silencio.
     */
    private function toFloat(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $text = trim((string) $raw);

        if ($text === '' || $text === '-') {
            return null;
        }

        // Espacio fino o espacio normal como separador de miles: "1 234,56".
        $text = str_replace(["\u{00A0}", ' '], '', $text);

        // El punto solo es separador de miles si hay coma despues; si esta solo,
        // es el separador decimal.
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }
}
