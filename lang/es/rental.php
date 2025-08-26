<?php

return [
    'navigation' => [
        'group' => 'Administración',
        'labels' => [
            'singular' => 'alquiler',
            'plural' => 'Alquileres',
        ],
        'pages' => [
            'view' => 'Ver alquiler',
            'edit' => 'Editar alquiler',
        ],
    ],

    'form' => [
        'sections' => [
            'main' => [
                'start_date'   => 'Fecha de inicio',
                'end_date'     => 'Fecha de finalización',
                'name'         => 'Nombre',
                'total_months' => 'Meses totales',
                'total_persons' => 'Número de personas',
                'monthly_amount' => 'Monto mensual',
                'tenant'       => 'Inquilino',
                'property'     => 'Propiedad',
                'is_active'    => 'Activo',
                'description'  => 'Descripción',
            ],
            'agreement' => [
                'title'       => 'Contrato',
                'description' => 'El documento del contrato es opcional.',
                'hint'        => 'Solo .pdf, .docx o imágenes (.jpg, .png), hasta 5MB',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name'          => 'Nombre',
            'start_date'    => 'Fecha inicio',
            'end_date'      => 'Fecha fin',
            'total_months'  => 'Meses',
            'total_persons' => 'Personas',
            'monthly_amount' => 'Monto mensual',
            'agreement'     => 'Contrato',
            'is_active'     => 'Activo',
            'tenant'        => 'Inquilino',
            'property'      => 'Propiedad',
            'description'   => 'Descripción',
            'created_at'    => 'Creado el',
            'updated_at'    => 'Actualizado el',
        ],
    ],

    'infolist' => [
        'sections' => [
            'main' => [
                'start_date'   => 'Fecha de inicio',
                'end_date'     => 'Fecha de finalización',
                'name'         => 'Nombre',
                'total_months' => 'Meses totales',
                'total_persons' => 'Número de personas',
                'monthly_amount' => 'Monto mensual',
                'tenant'       => 'Inquilino',
                'property'     => 'Propiedad',
                'is_active'    => 'Activo',
                'description'  => 'Descripción',
                'created_at' => 'Creado el',
                'updated_at' => 'Actualizado el',
            ],
            'agreement' => [
                'title'          => 'Contrato',
                'empty'          => 'No se ha subido ningún documento ni imagen',
                'download_image' => 'Descargar imagen',
            ],
        ],
    ],
];
