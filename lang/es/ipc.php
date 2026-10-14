<?php

return [

    'basis' => [
        'national' => 'Total nacional (art. 20)',
        'city' => 'Ciudad del inmueble',
    ],

    'strategies' => [
        'annual_variation' => 'Variación anual de diciembre (la que exige el artículo 20)',
        'index_ratio' => 'Razón entre índices del IPC',
        'informative_marker' => 'informativo, no es el tope legal',
        'frozen' => 'IPC elegido explícitamente',
    ],

    'source' => [
        'label' => 'Fuente',
        'dane' => 'DANE',
        'synced_at' => 'Sincronizado el',
        'never_synced' => 'Nunca se ha sincronizado con el DANE',
    ],

    'filters' => [
        'all' => 'Todos',
    ],

    'navigation' => [
        'group' => 'Información',
        'label' => 'Serie del IPC (DANE)',
    ],

    'model' => [
        'singular' => 'dato del IPC',
        'plural' => 'datos del IPC',
    ],

    'table' => [
        'year' => 'Año',
        'variation_year_hint' => 'Es la que usa el artículo 20',
        'geography' => 'Geografía',
        'period' => 'Periodo',
        'index' => 'Índice',
        'variation_monthly' => 'Var. mensual',
        'variation_yoy' => 'Var. anual',
        'variation_year' => 'Var. año corrido',
        'synced_at' => 'Sincronizado',
        'source_url' => 'Ver documento',
        'source' => 'Fuente',
        'empty' => 'Todavía no hay datos del IPC cargados.',
    ],

    'messages' => [
        'no_data' => 'No hay datos del IPC cargados en el sistema.',
        'no_cities' => 'No hay ciudades con IPC cargadas en el sistema.',
        'last_sync' => 'Última carga: :date',
        'never' => 'sin datos',
        'stale' => 'Serie desactualizada',
        'stale_title' => 'La serie del IPC está desactualizada',
        'stale_body' => 'La última carga de datos del DANE es anterior a la fecha indicada. Los topes de los reajustes se calculan con esa serie, de modo que unIPC reciente puede no estar todavía en el sistema.',
    ],

];
