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
        'other' => 'Other concept',
    ],

    'methods' => [
        'efectivo' => 'Cash',
        'transferencia' => 'Bank transfer',
        'consignacion' => 'Deposit',
    ],

    'sections' => [
        'general' => 'Invoice details',
        'payment' => 'Record payment',
    ],

    'descriptions' => [
        'legal' => 'This document is an internal record. It is not an electronic invoice certified by the DIAN.',
        'number' => 'The consecutive number is generated automatically per landlord and year.',
        'payment' => 'Once the balance is covered, the invoice becomes Paid and its receipt becomes available.',
        'annul' => 'A voided invoice stops accepting payments and keeps its history.',
    ],

    'form' => [
        'rental_id' => 'Rental',
        'concept' => 'Concept',
        'custom_concept' => 'Concept detail',
        'amount' => 'Total amount',
        'period' => 'Billed period',
        'issued_at' => 'Issue date',
        'due_at' => 'Due date',
        'notes' => 'Notes',
        'payment_amount' => 'Amount paid',
        'payment_date' => 'Payment date',
        'payment_method' => 'Payment method',
        'payment_reference' => 'Reference',
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
            'hint' => 'Pick the format before generating the document. Each document is delivered in a single format.',
        ],
        'copy' => 'Copy',
        'download' => 'Download',
        'copied' => 'Document copied to clipboard',
        'copy_failed' => 'Could not copy. Open the document and copy it manually.',
        'generated' => 'Document generated',
        'empty_statement' => 'There are no invoices in the selected period.',
        'hint' => [
            'statement' => 'The statement gathers every invoice of the rental in the period and closes with the real balance.',
            'invoice' => 'The invoice prints even when pending: it is the document used to collect.',
            'adjustment' => 'The letter cites the CPI applied and its source, and warns when the increase exceeds the legal cap.',
        ],
        'previews' => [
            'receipt' => 'Payment receipt',
            'invoice' => 'Rental invoice',
            'statement' => 'Statement',
            'adjustment' => 'Adjustment letter',
        ],
    ],

    'created' => 'Invoice created',
    'payment_recorded' => 'Payment recorded',
    'annulled' => 'Invoice voided',
    'receipt_locked' => 'The receipt becomes available once the invoice is paid.',
    'cannot_receive_payments' => 'This invoice does not accept more payments.',
    'no_rentals' => 'There are no active rentals. Create a rental before invoicing.',
];
