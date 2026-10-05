<?php

return [
    'title' => 'Invoices',
    'navigation_label' => 'Invoices',

    'navigation' => [
        'group' => 'Administration',
        'labels' => [
            'singular' => 'invoice',
            'plural' => 'invoices',
        ],
    ],

    'statuses' => [
        'emitida' => 'Issued',
        'pagada' => 'Paid',
        'vencida' => 'Overdue',
        'anulada' => 'Voided',
    ],

    'concepts' => [
        'rent' => 'Rent',
        'services' => 'Monthly services',
        'rent_services' => 'Rent and services',
        'adjustment' => 'IPC adjustment',
        'other' => 'Other',
    ],

    'methods' => [
        'efectivo' => 'Cash',
        'transferencia' => 'Transfer',
        'consignacion' => 'Consignation',
    ],

    'sections' => [
        'general' => 'Invoice details',
        'payment' => 'Record payment',
    ],

    'descriptions' => [
        'legal' => 'This document is an internal record. It is not an electronic invoice certified by the DIAN.',
        'number' => 'The consecutive number is generated automatically by landlord and year.',
        'payment' => 'When the total balance is covered, the invoice status changes to Paid and the receipt is enabled.',
        'annul' => 'A voided invoice no longer accepts payments and preserves its history.',
    ],

    'form' => [
        'rental_id' => 'Rental',
        'concept' => 'Concept',
        'custom_concept' => 'Concept details',
        'amount' => 'Total amount',
        'period' => 'Billing period',
        'issued_at' => 'Issue date',
        'due_at' => 'Due date',
        'notes' => 'Observations',
        'payment_amount' => 'Payment amount',
        'payment_date' => 'Payment date',
        'payment_method' => 'Payment method',
        'payment_reference' => 'Reference',
        'receipt_image' => 'Receipt image',
        'status' => 'Status',
    ],

    'placeholders' => [
        'concept' => 'Rent, March 2026',
        'period' => '2026-03',
        'notes' => 'Invoice for contract 2026-01.',
    ],

    'validation' => [
        'period' => 'The period is written as YYYY-MM, for example 2026-03.',
    ],

    'table' => [
        'number' => 'Number',
        'concept' => 'Concept',
        'rental' => 'Rental',
        'amount' => 'Total',
        'balance' => 'Balance',
        'period' => 'Period',
        'issued_at' => 'Issued',
        'due_at' => 'Due',
        'status' => 'Status',
    ],

    'buttons' => [
        'create' => 'Create invoice',
        'record_payment' => 'Record payment',
        'annul' => 'Void',
        'download_receipt' => 'Receipt',
        'download_invoice' => 'Invoice',
        'copy_receipt' => 'Copy receipt',
        'copy_invoice' => 'Copy invoice',
        'statement' => 'Statement',
        'adjustment_letter' => 'Adjustment letter',
        'download_document_pdf' => 'Download PDF',
        'copy_document_text' => 'Copy text',
    ],

    'documents' => [
        'title' => 'Documents',
        'format' => [
            'label' => 'Format',
            'text' => 'Text',
            'pdf' => 'PDF',
            'hint' => 'Choose the format before generating the document. Each document is delivered complete in a single format.',
        ],
        'copy' => 'Copy',
        'download' => 'Download',
        'copied' => 'Document copied to clipboard',
        'copy_failed' => 'Could not copy. Open the document and copy it manually.',
        'generated' => 'Document generated',
        'empty_statement' => 'No invoices in the specified period.',
        'hint' => [
            'statement' => 'The statement gathers all invoices for the rental in the period and closes with the real balance.',
            'invoice' => 'The invoice is printed even if pending: it is the document used for collection.',
            'adjustment' => 'The adjustment letter cites the IPC applied and its source, and warns if the increase exceeds the legal cap.',
        ],
        'previews' => [
            'receipt' => 'Payment receipt',
            'invoice' => 'Rent invoice',
            'statement' => 'Statement',
            'adjustment' => 'Adjustment letter',
        ],
    ],

    'created' => 'Invoice created',
    'payment_recorded' => 'Payment recorded',
    'annulled' => 'Invoice voided',
    'receipt_locked' => 'The receipt is enabled when the invoice is paid.',
    'cannot_receive_payments' => 'This invoice no longer accepts payments.',
    'no_rentals' => 'No active rentals. Create a rental before invoicing.',
];
