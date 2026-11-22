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

    /*
    | Destino del inmueble. El articulo 1 de la Ley 820 limita la ley a vivienda
    | urbana: un local comercial se rige por el Codigo de Comercio y no tiene tope
    | legal del IPC.
    */
    'types' => [
        'residential' => 'Vivienda urbana',
        'commercial' => 'Local comercial',
    ],

    'form' => [
        'sections' => [
            'main' => [
                'start_date' => 'Fecha de inicio',
                'end_date' => 'Fecha de finalización',
                'name' => 'Nombre',
                'total_months' => 'Meses totales',
                'total_persons' => 'Número de personas',
                'monthly_amount' => 'Monto mensual',
                'billing_cadence' => 'Cadencia de cobro',
                'tenant' => 'Inquilino',
                'property' => 'Propiedad',
                'is_active' => 'Activo',
                'description' => 'Descripción',
            ],
            'legal' => [
                'title' => 'Datos legales del inmueble',
                'description' => 'El artículo 1 limita la Ley 820 de 2003 a la vivienda urbana, y el artículo 18 limita el canon al uno por ciento del valor comercial. Estos datos son los que hacen posibles esas dos comprobaciones.',
                'rental_type' => 'Tipo de alquiler',
                'rental_type_help' => 'La Ley 820 se aplica a la vivienda urbana. En un inmueble comercial el canon lo determina el mercado y el IPC es una referencia, no un límite legal.',
                'commercial_value' => 'Valor comercial',
                'commercial_value_help' => 'Estimación comercial del inmueble. El artículo 18 exige que no supere el equivalente a dos veces el avalúo catastral.',
                'catastral_appraisal' => 'Avalúo catastral',
                'catastral_appraisal_help' => 'Avalúo catastral vigente. Es el que sirve de techo al valor comercial del artículo 18.',
            ],
            'agreement' => [
                'title' => 'Contrato',
                'description' => 'El documento del contrato es opcional.',
                'hint' => 'Solo .pdf, .docx o imágenes (.jpg, .png), hasta 5MB',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name' => 'Nombre',
            'start_date' => 'Fecha inicio',
            'end_date' => 'Fecha fin',
            'total_months' => 'Meses',
            'total_persons' => 'Personas',
            'monthly_amount' => 'Monto mensual',
            'billing_cadence' => 'Cadencia',
            'agreement' => 'Contrato',
            'is_active' => 'Activo',
            'tenant' => 'Inquilino',
            'property' => 'Propiedad',
            'description' => 'Descripción',
            'created_at' => 'Creado el',
            'updated_at' => 'Actualizado el',
        ],
    ],

    'infolist' => [
        'sections' => [
            'main' => [
                'start_date' => 'Fecha de inicio',
                'end_date' => 'Fecha de finalización',
                'name' => 'Nombre',
                'total_months' => 'Meses totales',
                'total_persons' => 'Número de personas',
                'monthly_amount' => 'Monto mensual',
                'billing_cadence' => 'Cadencia de cobro',
                'tenant' => 'Inquilino',
                'property' => 'Propiedad',
                'is_active' => 'Activo',
                'description' => 'Descripción',
                'created_at' => 'Creado el',
                'updated_at' => 'Actualizado el',
            ],
            'agreement' => [
                'title' => 'Contrato',
                'empty' => 'No se ha subido ningún documento ni imagen',
                'download_image' => 'Descargar imagen',
            ],
        ],
    ],
];
