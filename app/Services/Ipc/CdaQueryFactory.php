<?php

namespace App\Services\Ipc;

use App\Dto\Geography;

/**
 * Arma las peticiones del CDA del DANE.
 *
 * El servicio CDA de Pentaho no tiene una API de consulta con parametros: es un
 * motor de SQL al que se le mandan los fragmentos de la consulta como campos de
 * formulario. Esta clase es la unica que conoce esa forma.
 *
 * Se separa del adaptador a proposito. Aqui se decide QUE se pregunta y con que
 * proteccion; en el adaptador se decide COMO se transporta y como se normaliza
 * la respuesta. Si el DANE cambia el transporte, se toca el adaptador. Si cambia
 * el esquema, se toca la configuracion. Si hay que filtrar por otra dimension,
 * se toca aqui.
 *
 * La seguridad no depende de disciplina: todo lo que entra al SQL pasa por
 * SqlScalar, que no se puede construir con un valor invalido.
 */
class CdaQueryFactory
{
    /**
     * La consulta de la serie del IPC.
     *
     * El WHERE se compone de cuatro bloques, en este orden:
     *
     *   1. las uniones, que son obligatorias y no admiten variantes;
     *   2. el nivel de ingresos, que elige "Total Nivel de Ingresos" (12);
     *   3. el nivel de la canasta, que elige el agregado (14) y no una division;
     *   4. los limites: geografia, anos y meses.
     *
     * El bloque 3 es el que la guia original resolvió mal. Detallar por que esta
     * en config/dane_ipc_sql.php; aqui solo queda la consecuencia: se pide el
     * nivel 14 y nada mas.
     *
     * @return array<string, string> los campos del formulario del POST.
     */
    public function seriesQuery(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array
    {
        $sql = config('dane_ipc_sql');

        // array_merge y no el operador +: con +, una clave repetida gana la de la
        // izquierda y la de la derecha se descarta en silencio. Eso es lo que
        // hacia que las consultas auxiliares terminaran pidiendo ktlDinamico.
        return array_merge($this->baseFields($sql['queries']['series']), [
            'paramparsSelect' => $sql['select'],
            'paramparsFrom' => $sql['from'],
            'paramparsWhere' => $this->seriesWhere($sql, $geographyCode, $fromYear, $toYear),
            'paramparsOrderby' => $sql['orderby'],
            'paramsearchBox' => '',
        ]);
    }

    /**
     * Una consulta auxiliar del CDA, como qryCiudad o qryAnio.
     *
     * Se averiguo que estas no piden parametros: basta con el nombre en
     * dataAccessId, sin los fragmentos de SQL que exige ktlDinamico. Sus columnas
     * reales estan anotadas en config/dane_ipc_sql.php.
     *
     * @return array<string, string>
     */
    public function simpleQuery(string $dataAccessId): array
    {
        return $this->baseFields($dataAccessId);
    }

    /**
     * Los campos que el CDA exige en toda peticion.
     *
     * @return array<string, string>
     */
    private function baseFields(string $dataAccessId): array
    {
        return [
            'path' => (string) config('services.dane_ipc.cda_path'),
            'dataAccessId' => $dataAccessId,
            'outputIndexId' => '1',
            // pageSize 0 significa "sin paginar". La serie nacional entera son
            // unas 800 filas: paginar solo anade una llamada de mas.
            'pageSize' => '0',
            'pageStart' => '0',
            'sortBy' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $sql
     */
    private function seriesWhere(array $sql, string $geographyCode, ?int $fromYear, ?int $toYear): string
    {
        $conditions = $sql['joins'];

        $conditions[] = sprintf(
            'H.CPT_ID IN (%s)',
            SqlScalar::quotedIdentifiers([$sql['income_level']])->__toString(),
        );

        // El nivel 14 es el agregado "Total". Los niveles 15 a 18 son divisiones,
        // grupos, clases y subclases, y filtrar por ellos devuelve una porcion de la
        // canasta cuyo IPC no es el que el DANE certifica como IPC.
        $conditions[] = sprintf(
            'C.CIP_NIVEL_ESTRUCTURA IN (%s)',
            SqlScalar::quotedIdentifiers([$sql['basket_level']])->__toString(),
        );

        $conditions[] = sprintf(
            'H.PAI_ID = %s',
            SqlScalar::geographyCode($geographyCode)->__toString(),
        );

        $conditions[] = sprintf(
            'T.TIE_ANO IN (%s)',
            SqlScalar::yearRange(
                $fromYear ?? (int) $sql['min_year'],
                $toYear ?? (int) now()->year,
            )->__toString(),
        );

        $conditions[] = sprintf(
            'TT.TIE_MES IN (%s)',
            SqlScalar::allMonths()->__toString(),
        );

        return implode(' AND ', $conditions);
    }

    /**
     * El codigo con el que el total nacional se consulta en el CDA.
     */
    public function nationalCode(): string
    {
        return Geography::nationalCode();
    }
}
