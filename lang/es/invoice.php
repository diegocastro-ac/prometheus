<?php

return [
    'title' => 'Facturas',
    'navigation_label' => 'Facturas',

    'navigation' => [
        'group' => 'Administración',
        'labels' => [
            'singular' => 'factura',
            'plural' => 'facturas',
        ],
    ],

    'statuses' => [
        'emitida' => 'Emitida',
        'pagada' => 'Pagada',
        'vencida' => 'Vencida',
        'anulada' => 'Anulada',
    ],

    'concepts' => [
        'rent' => 'Canon de alquiler',
        'services' => 'Servicios del mes',
        'rent_services' => 'Servicios y canon',
        'adjustment' => 'Ajuste por IPC',
        'other' => 'Otro concepto',
    ],

    'methods' => [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'consignacion' => 'Consignación',
    ],

    'sections' => [
        'general' => 'Datos de la factura',
        'payment' => 'Registrar pago',
    ],

    'descriptions' => [
        'legal' => 'Este documento es un respaldo interno. No es una factura electrónica autorizada por la DIAN.',
        'number' => 'El consecutivo se genera automáticamente por arrendador y año.',
        'payment' => 'Al cubrir el saldo total, la factura pasa a Pagada y se habilita su comprobante.',
        'annul' => 'Una factura anulada deja de admitir pagos y conserva su histórico.',
    ],

    'form' => [
        'rental_id' => 'Alquiler',
        'concept' => 'Concepto',
        'custom_concept' => 'Detalle del concepto',
        'amount' => 'Valor total',
        'period' => 'Periodo facturado',
        'issued_at' => 'Fecha de emisión',
        'due_at' => 'Fecha de vencimiento',
        'notes' => 'Observaciones',
        'payment_amount' => 'Valor pagado',
        'payment_date' => 'Fecha del pago',
        'payment_method' => 'Medio de pago',
        'payment_reference' => 'Referencia',
        'receipt_image' => 'Imagen del comprobante',
        'status' => 'Estado',
    ],

    'placeholders' => [
        'concept' => 'Canon de alquiler, marzo 2026',
        'period' => '2026-03',
        'notes' => 'Factura correspondiente al contrato 2026-01.',
    ],

    'validation' => [
        'period' => 'El periodo se escribe como AAAA-MM, por ejemplo 2026-03.',
    ],

    'table' => [
        'number' => 'Número',
        'concept' => 'Concepto',
        'rental' => 'Alquiler',
        'amount' => 'Total',
        'balance' => 'Saldo',
        'period' => 'Periodo',
        'issued_at' => 'Emitida',
        'due_at' => 'Vence',
        'status' => 'Estado',
    ],

    'buttons' => [
        'create' => 'Crear factura',
        'record_payment' => 'Registrar pago',
        'annul' => 'Anular',
        'download_receipt' => 'Comprobante',
        'download_invoice' => 'Factura',
        'copy_receipt' => 'Copiar comprobante',
        'copy_invoice' => 'Copiar factura',
        'statement' => 'Estado de cuenta',
        'adjustment_letter' => 'Carta de reajuste',
        'download_document_pdf' => 'Descargar PDF',
        'copy_document_text' => 'Copiar texto',
    ],

    'documents' => [
        'title' => 'Documentos',
        'format' => [
            'label' => 'Formato',
            'text' => 'Texto',
            'pdf' => 'PDF',
            'hint' => 'Elija el formato antes de generar el documento. Cada documento se entrega completo en un solo formato.',
        ],
        'copy' => 'Copiar',
        'download' => 'Descargar',
        'copied' => 'Documento copiado al portapapeles',
        'copy_failed' => 'No se pudo copiar. Abra el documento y cópielo a mano.',
        'generated' => 'Documento generado',
        'empty_statement' => 'No hay facturas en el periodo indicado.',
        'hint' => [
            'statement' => 'El estado de cuenta reúne todas las facturas del alquiler en el periodo y cierra con el saldo real.',
            'invoice' => 'La factura se imprime aunque esté pendiente: es el documento con el que se cobra.',
            'adjustment' => 'La carta cita el IPC aplicado y su fuente, y avisa si el incremento supera el tope legal.',
        ],
        'previews' => [
            'receipt' => 'Comprobante de pago',
            'invoice' => 'Factura de arrendamiento',
            'statement' => 'Estado de cuenta',
            'adjustment' => 'Carta de reajuste',
        ],
    ],

    'created' => 'Factura creada',
    'payment_recorded' => 'Pago registrado',
    'annulled' => 'Factura anulada',
    'receipt_locked' => 'El comprobante se habilita cuando la factura está pagada.',
    'cannot_receive_payments' => 'Esta factura no admite más pagos.',
    'no_rentals' => 'No hay alquileres activos. Crea un alquiler antes de facturar.',
];
