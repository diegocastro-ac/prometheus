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

    'inventory_tree' => [
        'items' => ':count elemento|:count elementos',
        'empty' => 'Esta acta no tiene inventario registrado.',
    ],

    'descriptions' => [
        'inventory' => 'Agrupa los elementos por espacio. Los espacios pueden tener subespacios y se muestran con su ruta completa; los elementos se eligen del catálogo maestro del arrendador.',
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
        'space_edit' => 'Editar espacio',
        'space_name' => 'Nombre del espacio',
        'space_parent' => 'Espacio padre',
        'space_parent_none' => 'Sin padre (raíz)',
        'item_name' => 'Nombre del elemento',
        'item_master' => 'Elemento',
        'item_master_placeholder' => 'Seleccione o cree un elemento',
        'item_master_new' => 'Nombre del nuevo elemento',
        'item_master_new_help' => 'Se agrega a su catálogo y queda disponible para los próximos inventarios.',
        'item_master_edit' => 'Editar elemento',
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
        'inventory_tree' => 'Ver árbol del inventario',
    ],

    'buttons' => [
        'create' => 'Crear acta',
        'save' => 'Guardar',
        'close' => 'Cerrar',
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
        'space_not_in_catalog' => 'El espacio ":space" no está en su catálogo. Puede usar uno de los existentes (:catalog) o crear este con el botón contiguo.',
        'space_duplicate' => 'Ya existe un espacio con ese nombre en el mismo nivel.',
        'invalid_space_parent' => 'El espacio padre no es válido o crearía un ciclo en el árbol.',
        'inventory_item_required' => 'Cada elemento del inventario necesita un espacio y un elemento del catálogo.',
        'master_item_name_required' => 'El elemento del inventario necesita un nombre.',
        'master_item_duplicate' => 'Ya existe un elemento con ese nombre en su catálogo.',
        'master_item_not_in_catalog' => 'El elemento ":item" no está en el catálogo de este arrendador. Puede elegir uno de los existentes (:catalog) o crear este con el botón contiguo.',
        'master_catalog_empty' => 'catálogo vacío',
    ],
];
