<?php

return [
    'navigation' => [
        'group' => 'Administración',
        'labels' => [
            'singular' => 'propiedad',
            'plural' => 'Propiedades',
        ],
    ],

    'form' => [
        'name' => 'Nombre',
        'address' => 'Dirección',
        'description' => 'Descripción',
        'geography_code' => 'Ciudad',
        'geography_code_help' => 'La ciudad donde se encuentra el inmueble. Es el dato que determina el IPC de los reajustes y el valor catastral de referencia, por lo que debe corresponder con la ubicación real del inmueble. El DANE publica el IPC para un grupo de ciudades; las que no aparecen marcadas con "con IPC" solo podrán reajustarse con el total nacional.',
    ],

    'table' => [
        'columns' => [
            'name' => 'Nombre',
            'address' => 'Dirección',
            'description' => 'Descripción',
            'geography' => 'Ciudad',
            'geography_none' => 'Sin ciudad',
            'created_at' => 'Creado el',
            'updated_at' => 'Actualizado el',
        ],
    ],

    'infolist' => [
        'name' => 'Nombre',
        'address' => 'Dirección',
        'description' => 'Descripción',
        'geography' => 'Ciudad',
        'created_at' => 'Creado el',
        'updated_at' => 'Actualizado el',
    ],
];
