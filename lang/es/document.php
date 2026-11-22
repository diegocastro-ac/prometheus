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
    'invoices' => 'Facturas',
    'total_billed' => 'Total facturado',
    'statement_note' => 'Las fechas de vencimiento se consultan en cada factura. Las facturas anuladas no se cobran y se muestran solo como registro.',
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
    'warnings_title' => 'Advertencias',
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
    'warning_prefix' => 'AVISO:',
    'warning_exceeds_cap' => 'AVISO: el incremento pedido supera en %s lo que da el IPC del período. El artículo 20 de la Ley 820 de 2003 lo formula como límite, y en la práctica las partes pueden acordar más si lo dejan por escrito. El canon que el IPC respalda es de %s.',
    'warning_city_basis' => 'AVISO: este reajuste se calculó con el IPC de %s y no con el total nacional. El artículo 20 habla de "el índice de precios al consumidor" sin distinguir ciudades, así que el porcentaje es una referencia acordada y no el tope que fija la norma.',
    'warning_commercial' => 'AVISO: este inmueble está registrado como local comercial. La Ley 820 se aplica a vivienda urbana, de modo que en este alquiler el porcentaje es una referencia de mercado y no un límite legal.',
    'cumulative_from_anchor' => 'Acumulado',
    'pactado_below' => 'Porcentaje pactado',
    'not_yet_notified' => 'Este reajuste aún no ha sido comunicado. El artículo 20 lo hace inoponible mientras no se informen el monto y la fecha de vigencia.',
    'note_communication' => 'Esta carta cumple el deber de comunicación del artículo 20 de la Ley 820 de 2003. El incremento no es oponible al arrendatario si no se le informa el monto y la fecha, y esa comunicación debe hacerse por el servicio postal autorizado o por el mecanismo pactado en el contrato.',
    'note_ipc_source' => 'Fuente del indicador: %s, IPC del %s (%s).',
    'notified_on' => 'Comunicada el',

    // Footer
    'generated_by' => 'Generado por Prometheus el',
    'page' => 'Página',
    'of' => 'de',
];
