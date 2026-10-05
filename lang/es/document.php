<?php

return [
    // Invoice
    'invoice' => 'Factura',
    'payment_receipt' => 'Comprobante de Pago',
    'adjustment_letter' => 'Carta de Reajuste IPC',
    'monthly_statement' => 'Estado de Cuenta Mensual',

    // Invoice fields
    'invoice_number' => 'Factura No.',
    'date' => 'Fecha',
    'due_date' => 'Fecha de Vencimiento',
    'issued_date' => 'Fecha de Emisión',
    'payment_date' => 'Fecha de Pago',
    'period' => 'Periodo',
    'billing_period' => 'Periodo de Facturación',
    'billing_cadence' => 'Cadencia de Cobro',
    'service_type' => 'Tipo de Servicio',
    'monthly_rate' => 'Tarifa Mensual',
    'contract_months' => 'Meses de Contrato',
    'occupants' => 'Ocupantes',

    // Customer Information
    'customer_information' => 'Información del Cliente',
    'customer_name' => 'Nombre del Cliente',
    'document_id' => 'Documento de Identidad',
    'phone' => 'Teléfono',
    'email' => 'Correo Electrónico',

    // Contract Information
    'contract_information' => 'Información del Contrato',
    'contract_number' => 'Número de Contrato',
    'contract_start' => 'Inicio de Contrato',
    'contract_end' => 'Fin de Contrato',
    'contract_duration' => 'Duración del Contrato',
    'months' => 'meses',

    // Service Location
    'service_location' => 'Ubicación del Servicio',
    'property_name' => 'Nombre de Propiedad',
    'property_description' => 'Descripción de Propiedad',
    'service_address' => 'Dirección del Servicio',
    'rental_unit' => 'Unidad de Arrendamiento',
    'unit' => 'Unidad',

    // Billing Information
    'billing_information' => 'Información de Facturación',
    'contract_notes' => 'Notas del Contrato',

    // Invoice Details
    'invoice_details' => 'Detalles de Factura',
    'description' => 'Descripción',
    'rate' => 'Tarifa',
    'amount' => 'Monto',
    'subtotal' => 'Subtotal',
    'tax' => 'Impuesto',
    'total_due' => 'Total a Pagar',

    // Status
    'invoice_summary' => 'Resumen de Factura',
    'invoice_amount' => 'Monto de Factura',
    'paid_amount' => 'Monto Pagado',
    'outstanding_balance' => 'Saldo Pendiente',
    'paid' => 'PAGADA',
    'issued' => 'EMITIDA',
    'overdue' => 'VENCIDA',
    'voided' => 'ANULADA',

    // Payment Status
    'payment_status' => 'Estado de Pago',
    'payment_details' => 'Detalles de Pago',
    'payer_information' => 'Información del Pagador',
    'customer' => 'Cliente',
    'property' => 'Propiedad',
    'contract' => 'Contrato',
    'amount_paid' => 'Monto Pagado',
    'total_paid' => 'Total Pagado',
    'payment_breakdown' => 'Desglose de Pagos',
    'method' => 'Método',
    'reference' => 'Referencia',
    'receipt_image' => 'Imagen del Comprobante',

    // Account Information
    'account_information' => 'Información de Cuenta',
    'tenant' => 'Inquilino',

    // Transaction History
    'transaction_history' => 'Historial de Transacciones',
    'concept' => 'Concepto',
    'balance' => 'Saldo',
    'status' => 'Estado',
    'total_invoiced' => 'Total Facturado',
    'total_paid_total' => 'Total Pagado',
    'outstanding_balance_total' => 'Saldo Pendiente',

    // Adjustment
    'property_information' => 'Información de Propiedad',
    'adjustment_details' => 'Detalles de Reajuste',
    'previous' => 'Anterior',
    'ipc_rate' => 'Tasa IPC',
    'adjustment' => 'Reajuste',
    'new_amount' => 'Nuevo Monto',
    'previous_rent' => 'Canon Anterior',
    'inflation_adjustment' => 'Ajuste por Inflación',
    'new_rent_amount' => 'Nuevo Canon',
    'legal_notice' => 'Aviso Legal',
    'legal_notice_text' => 'Este reajuste se basa en la tasa oficial del IPC publicada por el DANE. El canon ajustado entrará en vigencia a partir del próximo periodo de facturación.',

    // Notes
    'notes' => 'Notas',

    // Plain text sections
    'general_data' => 'Datos Generales',
    'details' => 'Detalle',
    'warnings' => 'Avisos',
    'paid_in_full' => 'PAGADA COMPLETAMENTE',

    // Receipt fields
    'rental' => 'Alquiler',
    'receipt_note' => 'Este comprobante acredita el pago de la factura',
    'receipt_note_suffix' => 'y no sustituye la factura electronica de venta.',

    // Invoice fields
    'total_due' => 'Total a Pagar',

    // Adjustment fields
    'effective_from' => 'Vigencia desde',
    'ipc_applied' => 'IPC aplicado',
    'legal_cap' => 'Tope legal',
    'warning_exceeds_cap' => 'AVISO: el incremento pedido supera en %s el tope legal del IPC. Según el artículo 20 de la Ley 820 de 2003, un incremento por encima del IPC solo opera si existe acuerdo escrito entre las partes. Sin ese acuerdo, el canon que queda vigente es el de %s.',
    'note_communication' => 'Esta carta cumple el deber de comunicación del artículo 20 de la Ley 820 de 2003. Si no se comunica el monto ni la fecha de vigencia, el reajuste es inoponible al arrendatario.',
    'note_ipc_source' => 'Fuente del indicador: %s, IPC del %s (%s).',
    'notified_on' => 'Comunicada el',

    // Footer
    'generated_by' => 'Generado por Prometheus el',
    'page' => 'Página',
    'of' => 'de',
];
