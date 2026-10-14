<?php

return [
    'title' => 'Actas de entrega',
    'navigation_label' => 'Actas de entrega',

    'navigation' => [
        'group' => 'Administración',
        'labels' => [
            'singular' => 'acta de entrega',
            'plural' => 'actas de entrega',
        ],
    ],

    'types' => [
        'entrega' => 'Entrega',
        'recepcion' => 'Recepción',
        'cambio_arrendatario' => 'Cambio de arrendatario',
    ],

    'sections' => [
        'parties' => 'Partes',
        'readings' => 'Lecturas de medidores',
        'inventory' => 'Inventario por espacio',
        'commitments' => 'Compromisos',
        'signatures' => 'Firmas',
    ],

    'descriptions' => [
        'inventory' => 'Agrupa los elementos por espacio. Puedes crear nuevos espacios al escribir.',
        'commitments' => 'Si no hay compromisos, deja la sección en blanco. No se imprimirá.',
        'readings' => 'Solo llena los medidores que se lean en la visita.',
    ],

    'form' => [
        'rental' => 'Alquiler',
        'type' => 'Tipo de acta',
        'occurred_at' => 'Fecha de la visita',
        'scheduled_at' => 'Hora programada',
        'landlord_name' => 'Nombre del arrendador',
        'landlord_document' => 'Documento del arrendador',
        'tenant_name' => 'Nombre del arrendatario',
        'tenant_document' => 'Documento del arrendatario',
        'water_reading' => 'Lectura de agua',
        'energy_reading' => 'Lectura de energía',
        'gas_reading' => 'Lectura de gas',
        'commitments' => 'Compromisos',
        'observations' => 'Observaciones',
        'space' => 'Espacio',
        'space_placeholder' => 'Seleccione o cree un espacio',
        'space_new' => 'Nombre del nuevo espacio',
        'space_new_help' => 'Se agrega a su catálogo y queda disponible para los próximos inventarios.',
        'space_edit' => 'Renombrar espacio',
        'space_name' => 'Nombre del espacio',
        'item_name' => 'Nombre del elemento',
        'item_state' => 'Estado del elemento',
        'item_note' => 'Nota',
        'item_photo' => 'Foto',
        'landlord_signature' => 'Firma del arrendador',
        'tenant_signature' => 'Firma del arrendatario',
        'signed_at' => 'Fecha de firma',
    ],

    'item_states' => [
        'nuevo' => 'Nuevo',
        'bueno' => 'Bueno',
        'reparable' => 'Reparable',
        'por_reemplazar' => 'Por reemplazar',
        'destruido' => 'Destruido',
    ],

    'table' => [
        'type' => 'Tipo',
        'tenant' => 'Arrendatario',
        'occurred_at' => 'Fecha',
        'items' => 'Elementos',
        'signed' => 'Firmada',
    ],

    'placeholders' => [
        'commitments' => 'El arrendatario se compromete a reportar la fuga de agua antes del 15.',
        'item_note' => 'Se recomienda pintar la puerta del patio principal.',
    ],

    'actions' => [
        'derive' => 'Crear desde esta',
    ],

    'buttons' => [
        'create' => 'Crear acta',
        'save' => 'Guardar',
    ],

    'created' => 'Acta creada',
    'derived' => 'Acta copiada en el formulario',

    /*
    | Los mensajes del Builder.
    |
    | Antes eran cadenas en PHP, sin tildes y con la instrucción de ir a otra
    | pantalla. Este último es el que cambió de fondo: decir «agreguelo en Ajustes»
    | obligaba al usuario a interrumpir el inventario para configurar un dato que
    | necesitaba en ese momento, y el formulario ya tiene un botón para crearlo.
    */
    'errors' => [
        'no_spaces' => 'Aún no hay espacios en su catálogo. Use el botón de creación junto al campo para agregar el primero.',
        'space_not_in_catalog' => 'El espacio «:space» no está en su catálogo. Puede usar uno de los existentes (:catalog) o crear este con el botón contiguo.',
    ],
];
