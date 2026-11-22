<?php

namespace Tests\Feature;

use App\Dto\Geography;
use App\Infrastructure\Ipc\DaneCdaIpcProvider;
use App\Services\Ipc\CdaQueryFactory;
use App\Services\Ipc\SqlScalar;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El adaptador del CDA del DANE (patron Adapter).
 *
 * Estas pruebas se escribieron CONTRA RESPUESTAS REALES capturadas del endpoint,
 * no contra una forma inventada. Eso es lo que las hace utiles: si el adaptador
 * compila, es porque traduce de verdad el formato que manda el DANE, con sus comas
 * decimales, sus meses abreviados y sus PAI_ID a los que se les cae el cero.
 *
 * Los valores que aparecen en los datos de prueba se verificaron uno por uno
 * contra el servicio:
 *
 *   Nacional, diciembre 2024 -> indice 144,88 ; variacion anual 5,2
 *   Nacional, diciembre 2023 -> indice 137,72 ; variacion anual 9,28
 *   Valledupar, diciembre 2025 -> indice 155,58 ; variacion anual 3,49
 *
 * Si un dia dejan de cuadrar, el problema no es el adaptador: es que el DANE
 * cambio. Para eso esta el comando ipc:doctor.
 */
class DaneCdaIpcProviderTest extends TestCase
{
    private function provider(): DaneCdaIpcProvider
    {
        return new DaneCdaIpcProvider(app(CdaQueryFactory::class));
    }

    /**
     * Una respuesta CDA con las filas que devuelve el endpoint real.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function cdaResponse(array $rows, ?int $totalRows = null): array
    {
        return [
            'queryInfo' => ['totalRows' => (string) ($totalRows ?? count($rows))],
            'metadata' => [
                ['colIndex' => 0, 'colType' => 'String', 'colName' => 'Año'],
                ['colIndex' => 1, 'colType' => 'String', 'colName' => 'Mes'],
                ['colIndex' => 2, 'colType' => 'String', 'colName' => 'Nivel de ingresos'],
                ['colIndex' => 3, 'colType' => 'String', 'colName' => 'Ciudad'],
                ['colIndex' => 4, 'colType' => 'Numeric', 'colName' => 'Número Índice'],
                ['colIndex' => 5, 'colType' => 'Numeric', 'colName' => 'Variación Mensual'],
                ['colIndex' => 6, 'colType' => 'Numeric', 'colName' => 'Variación Año Corrido'],
                ['colIndex' => 7, 'colType' => 'Numeric', 'colName' => 'Variación Anual'],
            ],
            'resultset' => $rows,
        ];
    }

    /**
     * El DANE escribe coma decimal. Si el adaptador no lo traduca, un 5,20 % se
     * convierte en 5 y el tope de todos los reajustes sale mal.
     */
    #[Test]
    public function traduce_la_coma_decimal_del_dane(): void
    {
        Http::fake([
            '*' => Http::response($this->cdaResponse([
                ['2024', 'Dic', 'Total Nivel de Ingresos', 'NACIONAL', '144,88', '0,46', '5,2', '5,2'],
            ])),
        ]);

        $series = $this->provider()->fetchSeries(Geography::nationalCode());

        $this->assertCount(1, $series);
        $this->assertSame(144.88, $series[0]->index);
        $this->assertSame(5.2, $series[0]->calendarYearVariation());
    }

    #[Test]
    public function traduce_los_meses_abreviados_a_numero(): void
    {
        Http::fake([
            '*' => Http::response($this->cdaResponse([
                ['2025', 'Ene', 'Total Nivel de Ingresos', 'NACIONAL', '153,18', '0,62', '0,62', '10,81'],
                ['2025', 'Sep', 'Total Nivel de Ingresos', 'NACIONAL', '165,01', '0,04', '8,39', '8,96'],
                ['2025', 'Dic', 'Total Nivel de Ingresos', 'NACIONAL', '165,25', '0,05', '8,55', '8,55'],
            ])),
        ]);

        $series = $this->provider()->fetchSeries(Geography::nationalCode());

        $this->assertSame([1, 9, 12], array_map(fn ($o): int => $o->month, $series));
        $this->assertSame(['2025-01', '2025-09', '2025-12'], array_map(fn ($o): string => $o->periodKey(), $series));
    }

    /**
     * El caso que mas caro sale si se ignora: el CDA entrega el PAI_ID como numero
     * y al perder el cero "08001" llega como 8001. El filtro escribiria un codigo
     * que el CDA no reconoce y devolveria cero filas EN SILENCIO.
     */
    #[Test]
    public function rellena_con_ceros_el_codigo_de_ciudad_que_llega_numerico(): void
    {
        $this->assertSame('08001', Geography::normaliseCode(8001));
        $this->assertSame('05001', Geography::normaliseCode(5001));
        $this->assertSame('20001', Geography::normaliseCode('20001'));
        $this->assertSame('20001', Geography::normaliseCode(20001));
    }

    /**
     * El total nacional no sigue la convencion DIVIPOLA: el CDA lo publica como
     * "50" y filtrar por "00050" no devuelve nada.
     */
    #[Test]
    public function el_codigo_nacional_se_deja_intacto(): void
    {
        $this->assertSame('50', Geography::nationalCode());
        $this->assertSame('50', Geography::normaliseCode('50'));
        $this->assertSame('50', Geography::normaliseCode(50));
        $this->assertNotSame('00050', Geography::normaliseCode('50'));
    }

    #[Test]
    public function mapea_las_columnas_por_nombre_y_no_por_posicion(): void
    {
        // Si el DANE anade una columna al final, el mapeo por posicion leeria el
        // numero del IPC donde va el nombre de la ciudad.
        $response = [
            'queryInfo' => ['totalRows' => '1'],
            'metadata' => [
                ['colIndex' => 0, 'colName' => 'Año'],
                ['colIndex' => 1, 'colName' => 'Mes'],
                ['colIndex' => 2, 'colName' => 'Número Índice'],
                ['colIndex' => 3, 'colName' => 'Variación Anual'],
                ['colIndex' => 4, 'colName' => 'Columna Nueva Del DANE'],
            ],
            'resultset' => [['2025', 'Dic', '152,27', '5,1', 'lo que sea']],
        ];

        Http::fake(['*' => Http::response($response)]);

        $series = $this->provider()->fetchSeries(Geography::nationalCode());

        $this->assertSame(152.27, $series[0]->index);
        $this->assertSame(5.1, $series[0]->calendarYearVariation());
    }

    #[Test]
    public function las_celadas_vacias_se_guardan_como_null_y_no_como_cero(): void
    {
        // Los primeros periodos de la serie vienen sin variacion. Guardarlos como
        // 0 seria afirmar que el IPC no cambio, que es una afirmacion distinta de
        // "no hay dato".
        Http::fake([
            '*' => Http::response($this->cdaResponse([
                ['1954', 'Jul', 'Total Nivel de Ingresos', 'NACIONAL', '0,03', '', '', ''],
            ])),
        ]);

        $series = $this->provider()->fetchSeries(Geography::nationalCode());

        $this->assertNull($series[0]->variationMonthly);
        $this->assertNull($series[0]->variationYear);
        $this->assertNull($series[0]->calendarYearVariation());
    }

    #[Test]
    public function una_fila_sin_indice_se_descarta_porque_sin_indice_no_hay_que_calcular(): void
    {
        Http::fake([
            '*' => Http::response($this->cdaResponse([
                ['2025', 'Dic', 'Total Nivel de Ingresos', 'NACIONAL', '', '0,05', '8,55', '8,55'],
            ])),
        ]);

        $this->assertSame([], $this->provider()->fetchSeries(Geography::nationalCode()));
    }

    #[Test]
    public function lee_el_catalogo_de_ciudades_del_dane(): void
    {
        // Las 23 ciudades que devuelve qryCiudad, con los codigos SIN cero, tal
        // como llegan.
        Http::fake([
            '*' => Http::response([
                'queryInfo' => ['totalRows' => '23'],
                'metadata' => [
                    ['colIndex' => 0, 'colType' => 'Numeric', 'colName' => 'PAI_ID'],
                    ['colIndex' => 1, 'colType' => 'String', 'colName' => 'MUN_NOM'],
                ],
                'resultset' => [
                    [8001, 'BARRANQUILLA'],
                    [5001, 'MEDELLÍN'],
                    [20001, 'VALLEDUPAR'],
                ],
            ]),
        ]);

        $geographies = $this->provider()->fetchGeographies();

        $codes = array_map(fn ($g): string => $g->code, $geographies);

        $this->assertSame(['08001', '05001', '20001'], $codes);
        $this->assertSame('VALLEDUPAR', $geographies[2]->name);
    }

    #[Test]
    public function reintenta_cuando_el_dane_responde_502(): void
    {
        // El CDA devuelve 502 de forma intermitente. Se comprobo al construir
        // esto, y es el motivo de los reintentos con espera creciente.
        config()->set('services.dane_ipc.retries', 3);
        config()->set('services.dane_ipc.retry_delay_ms', 1);

        Http::fake([
            '*' => Http::sequence()
                ->push('Service Unavailable', 502)
                ->push($this->cdaResponse([
                    ['2025', 'Dic', 'Total Nivel de Ingresos', 'NACIONAL', '152,27', '0,05', '5,1', '5,1'],
                ])),
        ]);

        $series = $this->provider()->fetchSeries(Geography::nationalCode());

        $this->assertCount(1, $series);
        $this->assertSame(152.27, $series[0]->index);
    }

    #[Test]
    public function una_peticion_que_no_es_json_falla_con_un_mensaje_que_diga_que_hacer(): void
    {
        config()->set('services.dane_ipc.retries', 1);

        Http::fake(['*' => Http::response('<html><body>Error</body></html>', 200)]);

        $this->expectException(\App\Exceptions\DaneIpcUnavailable::class);
        $this->expectExceptionMessage('es HTML, no JSON');

        $this->provider()->fetchSeries(Geography::nationalCode());
    }

    #[Test]
    public function un_200_con_otra_forma_avisa_que_el_esquema_pudo_cambiar(): void
    {
        config()->set('services.dane_ipc.retries', 1);

        Http::fake(['*' => Http::response(['otra' => 'cosa'], 200)]);

        $this->expectException(\App\Exceptions\DaneIpcUnavailable::class);
        $this->expectExceptionMessage('el DANE haya cambiado');

        $this->provider()->fetchSeries(Geography::nationalCode());
    }

    #[Test]
    public function la_consulta_de_una_ciudad_filtra_por_su_codigo_y_no_por_una_lista_de_exclusion(): void
    {
        Http::fake(['*' => Http::response($this->cdaResponse([]))]);

        $this->provider()->fetchSeries('20001');

        Http::assertSent(function ($request): bool {
            $where = urldecode((string) $request['paramparsWhere']);

            // La guia original excluia 25 codigos de ciudad para quedarse con el
            // total nacional. Se comprobo que el nacional es una fila propia con
            // PAI_ID 50, y que filtrar por una ciudad funciona.
            return str_contains($where, "H.PAI_ID = '20001'")
                && ! str_contains($where, 'NOT IN');
        });
    }

    /**
     * El filtro de la canasta es el error mas caro posible del modulo.
     *
     * La guia original pedia los niveles 14 a 18 con CIP_N18 = '437'. Eso devuelve
     * una PORCION de la serie, no el IPC: diciembre de 2024 da 10,80 % cuando el
     * IPC oficial fue 5,20 %. El filtro correcto es solo el nivel 14.
     */
    #[Test]
    public function la_consulta_pide_el_nivel_agregado_de_la_canasta_y_no_una_porcion(): void
    {
        Http::fake(['*' => Http::response($this->cdaResponse([]))]);

        $this->provider()->fetchSeries(Geography::nationalCode());

        Http::assertSent(function ($request): bool {
            $where = urldecode((string) $request['paramparsWhere']);

            return str_contains($where, "C.CIP_NIVEL_ESTRUCTURA IN ('14')")
                && ! str_contains($where, 'CIP_N18');
        });
    }

    #[Test]
    public function la_peticion_se_manda_como_formulario_y_no_como_json(): void
    {
        Http::fake(['*' => Http::response($this->cdaResponse([]))]);

        $this->provider()->fetchSeries(Geography::nationalCode());

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && $request['path'] === '/public/IPC/IPCV7.cda'
                && $request['dataAccessId'] === 'ktlDinamico';
        });
    }

    // -------------------------------------------------------------------
    // Seguridad del SQL
    // -------------------------------------------------------------------

    #[Test]
    public function un_codigo_de_ciudad_invalido_no_llega_al_sql(): void
    {
        // La garantia no es "recordar validar": es que el objeto que lleva el
        // valor al SQL no se puede construir con un valor invalido.
        $this->expectException(\InvalidArgumentException::class);

        SqlScalar::geographyCode("20001' OR '1'='1");
    }

    #[Test]
    public function un_codigo_de_ciudad_debe_tener_cinco_digitos(): void
    {
        $this->assertSame("'20001'", (string) SqlScalar::geographyCode('20001'));
        $this->assertSame("'50'", (string) SqlScalar::geographyCode('50'));

        $this->expectException(\InvalidArgumentException::class);
        SqlScalar::geographyCode('2001');
    }

    #[Test]
    public function un_ano_fuera_de_rango_se_rechaza(): void
    {
        $this->assertSame('2025', (string) SqlScalar::year(2025));

        $this->expectException(\InvalidArgumentException::class);
        SqlScalar::year(3000);
    }

    #[Test]
    public function el_rango_de_anos_acepta_un_intervalo_invertido(): void
    {
        // El tablero puede pedir los años al reves. Rechazarlo por eso seria
        // mas estricto de lo necesario.
        $this->assertSame('2020,2021,2022', (string) SqlScalar::yearRange(2022, 2020));
    }
}
