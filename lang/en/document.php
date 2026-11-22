<?php

return [
    // Invoice
    'invoice' => 'Invoice',
    'payment_receipt' => 'Payment Receipt',
    'adjustment_letter' => 'IPC Adjustment',
    'monthly_statement' => 'Monthly Statement',

    // Invoice fields
    'invoice_number' => 'Invoice No.',
    'date' => 'Date',
    'due_date' => 'Due Date',
    'issued_date' => 'Issued Date',
    'payment_date' => 'Payment Date',
    'period' => 'Period',
    'billing_period' => 'Billing Period',
    'billing_cadence' => 'Billing Cadence',
    'service_type' => 'Service Type',
    'monthly_rate' => 'Monthly Rate',
    'contract_months' => 'Contract Months',
    'occupants' => 'Occupants',

    // Customer Information
    'customer_information' => 'Customer Information',
    'customer_name' => 'Customer Name',
    'document_id' => 'Document ID',
    'phone' => 'Phone',
    'email' => 'Email',

    // Contract Information
    'contract_information' => 'Contract Information',
    'contract_number' => 'Contract Number',
    'contract_start' => 'Contract Start',
    'contract_end' => 'Contract End',
    'contract_duration' => 'Contract Duration',
    'months' => 'months',

    // Service Location
    'service_location' => 'Service Location',
    'property_name' => 'Property Name',
    'property_description' => 'Property Description',
    'service_address' => 'Service Address',
    'rental_unit' => 'Rental Unit',
    'unit' => 'Unit',

    // Billing Information
    'billing_information' => 'Billing Information',
    'contract_notes' => 'Contract Notes',

    // Invoice Details
    'invoice_details' => 'Invoice Details',
    'description' => 'Description',
    'rate' => 'Rate',
    'amount' => 'Amount',
    'subtotal' => 'Subtotal',
    'tax' => 'Tax',
    'total_due' => 'Total Due',

    // Status
    'invoice_summary' => 'Invoice Summary',
    'invoice_amount' => 'Invoice Amount',
    'paid_amount' => 'Paid Amount',
    'outstanding_balance' => 'Outstanding Balance',
    'paid' => 'PAID',
    'issued' => 'ISSUED',
    'overdue' => 'OVERDUE',
    'voided' => 'VOIDED',

    // Payment Status
    'payment_status' => 'Payment Status',
    'payment_details' => 'Payment Details',
    'payer_information' => 'Payer Information',
    'customer' => 'Customer',
    'property' => 'Property',
    'contract' => 'Contract',
    'amount_paid' => 'Amount Paid',
    'total_paid' => 'Total Paid',
    'payment_breakdown' => 'Payment Breakdown',
    'method' => 'Method',
    'reference' => 'Reference',
    'receipt_image' => 'Receipt Image',

    // Account Information
    'account_information' => 'Account Information',
    'tenant' => 'Tenant',

    // Transaction History
    'transaction_history' => 'Transaction History',
    'invoices' => 'Invoices',
    'total_billed' => 'Total Billed',
    'statement_note' => 'Due dates are checked on each invoice. Annulled invoices are not billed and are shown for the record only.',
    'concept' => 'Concept',
    'balance' => 'Balance',
    'status' => 'Status',
    'total_invoiced' => 'Total Invoiced',
    'total_paid_total' => 'Total Paid',
    'outstanding_balance_total' => 'Outstanding Balance',

    // Adjustment
    'property_information' => 'Property Information',
    'adjustment_details' => 'Adjustment Details',
    'previous' => 'Previous',
    'ipc_rate' => 'IPC Rate',
    'adjustment' => 'Adjustment',
    'new_amount' => 'New Amount',
    'previous_rent' => 'Previous Rent',
    'inflation_adjustment' => 'Inflation Adjustment',
    'new_rent_amount' => 'New Rent Amount',
    'legal_notice' => 'Legal Notice',
    'legal_notice_text' => 'This adjustment is based on the official IPC rate published by DANE. The adjusted rent will be effective from the next billing period.',

    // Notes
    'notes' => 'Notes',

    // Plain text sections
    'general_data' => 'General Data',
    'details' => 'Details',
    'warnings_title' => 'Warnings',
    'warnings' => 'Warnings',
    'paid_in_full' => 'PAID IN FULL',

    // Receipt fields
    'rental' => 'Rental',
    'receipt_note' => 'This receipt certifies payment of invoice',
    'receipt_note_suffix' => 'and does not replace the electronic sales invoice.',

    // Invoice fields
    'total_due' => 'Total Due',

    // Adjustment fields
    'effective_from' => 'Effective From',
    'ipc_applied' => 'IPC Applied',
    'legal_cap' => 'Legal Cap',
    'warning_prefix' => 'WARNING:',
    'warning_exceeds_cap' => 'WARNING: the requested increase exceeds what the CPI gives by %s. Article 20 of Law 820 of 2003 phrases this as a limit, and in practice the parties may agree more if they put it in writing. The rent the CPI supports is %s.',
    'warning_city_basis' => 'WARNING: this adjustment was calculated with the CPI of %s rather than the national total. Article 20 refers to "the consumer price index" without distinguishing cities, so the percentage is an agreed reference and not the limit set by the statute.',
    'warning_commercial' => 'WARNING: this property is registered as commercial premises. Law 820 applies to urban housing, so here the percentage is a market reference and not a legal limit.',
    'cumulative_from_anchor' => 'Cumulative',
    'pactado_below' => 'Agreed percentage',
    'not_yet_notified' => 'This adjustment has not been communicated yet. Under Article 20 it is unenforceable until the amount and effective date are notified.',
    'note_communication' => 'This letter fulfills the communication duty of Article 20 of Law 820 of 2003. The increase is not enforceable against the tenant unless the amount and effective date are communicated, and that communication must go through the authorised postal service or the mechanism agreed in the lease.',
    'note_ipc_source' => 'Indicator source: %s, CPI of %s (%s).',
    'notified_on' => 'Notified on',

    // Footer
    'generated_by' => 'Generated by Prometheus on',
    'page' => 'Page',
    'of' => 'of',
];
