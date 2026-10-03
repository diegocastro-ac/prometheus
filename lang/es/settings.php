<?php

return [
    'title' => 'Ajustes',
    'navigation_label' => 'Ajustes',

    'description' => 'Estos datos se imprimen en las facturas, los recibos y el acta de entrega. Se guardan una sola vez y los usan todos los documentos.',

    'sections' => [
        'business' => 'Datos del negocio',
        'contact' => 'Contacto',
        'billing' => 'Facturación',
        'inventory' => 'Catálogo de espacios',
    ],

    'descriptions' => [
        'billing' => 'Los documentos no son facturas electrónicas autorizadas por la DIAN.',
        'inventory' => 'Un espacio por línea. Se usa para agrupar el inventario del acta de entrega.',
    ],

    'form' => [
        'business_name' => 'Razón social',
        'tax_id' => 'NIT',
        'address' => 'Dirección',
        'currency' => 'Moneda',
        'logo' => 'Logotipo',
        'email' => 'Correo de notifications',
        'phone' => 'Teléfono',
        'invoice_due_days' => 'Días de vencimiento',
        'legal_footer' => 'Texto legal de los documentos',
        'space_catalog' => 'Espacios',
    ],

    'placeholders' => [
        'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
        'tax_id' => '900.123.456-7',
        'legal_footer' => 'Documento generado por el sistema de gestión de arrendamientos. No constituye factura electrónica.',
    ],

    'buttons' => [
        'save' => 'Guardar ajustes',
    ],

    'saved' => 'Ajustes guardados',
];