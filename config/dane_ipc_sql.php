<?php

/*
|--------------------------------------------------------------------------
| Plantillas SQL del CDA del DANE
|--------------------------------------------------------------------------
|
| El servicio CDA de Pentaho ejecuta literalmente el SQL que se le envia. Por
| eso estas cadenas son la parte mas fragil del sistema: son un esquema interno
| del DANE, no un contrato, y pueden cambiar sin aviso.
|
| Este archivo concentra TODA esa fragilidad. La regla es simple: si el DANE
| cambia algo, se corrige aqui y en ningun otro lado.
|
| Los valores verificados contra el endpoint real (HTTP 200) son:
|
|   Nacional, diciembre 2024 -> indice 144,88 ; variacion anual 5,2
|   Nacional, diciembre 2023 -> indice 137,72 ; variacion anual 9,28
|   Nacional, diciembre 2025 -> indice 152,27 ; variacion anual 5,1
|   Valledupar (20001), dic 2025 -> indice 155,58 ; variacion anual 3,49
|
| Esas cifras coinciden con el IPC oficial publicado por el DANE. Son el
| criterio de aceptacion: si 'php artisan ipc:doctor' deja de devolverlas, el
| esquema cambio y hay que recapturar la peticion desde el tablero.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Columnas
    |----------------------------------------------------------------------
    |
    | Los alias entre comillas son los colName de la respuesta. El adaptador
    | mapea por nombre y nunca por posicion, de modo que anadir una columna al
    | final no rompe nada.
    |
    */

    'select' => 'T.TIE_NOM_ANO "Año"'
        .',TT.TIE_NOM_MES "Mes"'
        .',CO.CPT_NOMBRE "Nivel de ingresos"'
        .',C.CIP_TITULO_NIVEL "Niveles de Canasta"'
        .',D.MUN_NOM "Ciudad"'
        .',numero_indice "Número Índice"'
        .',IPC_VARIACION_MENSUAL "Variación Mensual"'
        .',VARIACION_ANO_CORRIDO "Variación Año Corrido"'
        .',VARIACION_ANUAL "Variación Anual"',

    'from' => 'VWIPC_PAIS_CIUDAD H'
        .',DIM_TIEMPO T'
        .',DIM_TIEMPO TT'
        .',DIM_IPC_CONCEPTO CO'
        .',VWDIM_IPC_CANASTA C'
        .',VWDIM_IPC_CANASTA C1'
        .',VWDIM_IPC_CANASTA C2'
        .',VWDIM_IPC_CANASTA C3'
        .',VWDIM_IPC_CANASTA C4'
        .',VWDIM_IPC_CIUDAD D',

    'orderby' => ' T.TIE_ANO, T.TIE_MES, TT.TIE_MES',

    /*
    |----------------------------------------------------------------------
    | Uniones obligatorias
    |----------------------------------------------------------------------
    |
    | Los joins no son decorativos: sin ellos la consulta devuelve una fila por
    | cada subclase de la canasta. Se comprobo que quitar los joins de
    | VWDIM_IPC_CANASTA lleva la respuesta de 868 filas a 82.649, porque la
    | restriccion a CIP_N18 ya no selecciona una serie sino todas.
    |
    */

    'joins' => [
        'T.TIE_ID=H.TIE_ID',
        'TT.TIE_ID=H.TIE_ID',
        'CO.CPT_ID=H.CPT_ID',
        'D.MUN_ID=H.PAI_ID',
        'C.CIP_ID=H.CIP_ID',
        'C1.CIP_ID=H.CIP_ID',
        'C2.CIP_ID=H.CIP_ID',
        'C3.CIP_ID=H.CIP_ID',
        'C4.CIP_ID=H.CIP_ID',
    ],

    /*
    |----------------------------------------------------------------------
    | El filtro que decide que serie es "el IPC"
    |----------------------------------------------------------------------
    |
    | Este es el punto mas importante del archivo y el que la guia original
    | resolved mal.
    |
    | La guia capturaba la peticion del tablero con
    |   C.CIP_NIVEL_ESTRUCTURA IN ('14','15','16','17','18')  y
    |   C.CIP_N18 IN ('437')
    | y daba 333 filas. Funciona, pero NO es el IPC oficial: es una subcanasta.
    | Su diciembre 2024 da 10,80 % cuando el IPC oficial de 2024 fue 5,20 %.
    |
    | La causa es el rango de niveles. El nivel 14 es el agregado ("Total") y es
    | el unico que DANE certifica como IPC. Los niveles 15 a 18 son divisiones,
    | grupos, clases y subclases, y filtrar por ellos elige una restricción concreta
    | de la estructura, no el agregado.
    |
    | Con solo el nivel 14 la serie nacional reproduce exactamente las cifras
    | oficiales, que es el criterio de aceptacion de 'ipc:doctor'.
    |
    */

    'basket_level' => '14',

    /*
    |----------------------------------------------------------------------
    | Nivel de ingresos
    |----------------------------------------------------------------------
    |
    | El 12 es "Total Nivel de Ingresos": el promedio ponderado de todos los
    | estratos. Es el que usa la Ley 820. Los otros (8 Pobres, 9 Vulnerables,
    | 10 Clase Media, 11 Ingresos Altos) sirven para analisis y para la letra
    | pequena, no para reajustar.
    |
    */

    'income_level' => '12',

    /*
    |----------------------------------------------------------------------
    | Geografia
    |----------------------------------------------------------------------
    |
    | El total nacional NO es "todas las ciudades menos una lista". Es una fila
    | mas del propio modelo, con PAI_ID = 50 y nombre NACIONAL. Se comprobo que
    | filtrar por un codigo de ciudad inventado devuelve cero filas, mientras
    | que 'PAI_ID = 50' devuelve la serie completa.
    |
    | Esto elimina la lista de 25 codigos que la guia dedujo de la captura. Esa
    | lista era una conjetura: tenia 25 entradas y el IPC cubre mas ciudades, de
    | modo que nunca podia exhausting la cobertura.
    |
    */

    'national_code' => '50',

    'national_name' => 'NACIONAL',

    /*
    |----------------------------------------------------------------------
    | Consultas auxiliares del CDA
    |----------------------------------------------------------------------
    |
    | Las que alimentan los filtros dinamicos. Sus columnas reales se
    | verificaron una por una contra el endpoint.
    |
    */

    'queries' => [
        'series' => 'ktlDinamico',
        'cities' => 'qryCiudad',
        'years' => 'qryAnio',
        'months' => 'qryMes',
        'income_levels' => 'qryConcepto',
    ],

    /*
    |----------------------------------------------------------------------
    | Rango de anos
    |----------------------------------------------------------------------
    |
    | El primer ano que publica el CDA es 1954 y llega hasta el ano en curso.
    | Sin rango, el tablero genera la lista cada vez; aqui se fija para que la
    | consulta sea reproducible.
    |
    */

    'min_year' => 1954,

    // Columnas reales devueltas por cada consulta auxiliar.
    'columns' => [
        'cities' => ['code' => 'PAI_ID', 'name' => 'MUN_NOM'],
        'years' => ['year' => 'TIE_ANO'],
        'months' => ['number' => 'TIE_MES', 'name' => 'TIE_NOM_MES'],
        'income_levels' => ['id' => 'CPT_ID', 'name' => 'CPT_NOMBRE'],
    ],

    /*
    |----------------------------------------------------------------------
    | Cifras de control
    |----------------------------------------------------------------------
    |
    | Valores que el DANE cambio o que sirven para detectar que el esquema
    | cambio. 'ipc:doctor' los compara contra la respuesta real.
    |
    */

    'expected' => [
        // Nacional, diciembre de cada ano. Coinciden con el IPC oficial.
        2023 => 9.28,
        2024 => 5.20,
        2025 => 5.10,
    ],

    // Referencia cruzada para una ciudad, no usada como criterio de aceptacion.
    'expected_cities' => [
        '20001' => 3.49, // Valledupar, diciembre 2025.
    ],

];
