<?php

return [
    'navigation' => [
        'group' => 'Administration',
        'labels' => [
            'singular' => 'rental',
            'plural' => 'Rentals',
        ],
        'pages' => [
            'view' => 'View rental',
            'edit' => 'Edit rental',
        ],
    ],

    /*
    | Property type. Article 1 of Law 820 limits the statute to urban housing: a
    | commercial premises is governed by the Commercial Code and has no legal CPI cap.
    */
    'types' => [
        'residential' => 'Urban housing',
        'commercial' => 'Commercial premises',
    ],

    'form' => [
        'sections' => [
            'main' => [
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'name' => 'First name',
                'total_months' => 'Total months',
                'total_persons' => 'Number of people',
                'monthly_amount' => 'Monthly amount',
                'billing_cadence' => 'Billing cadence',
                'tenant' => 'Tenant',
                'property' => 'Property',
                'is_active' => 'Active',
                'description' => 'Description',
            ],
            'legal' => [
                'title' => 'Legal details of the property',
                'description' => 'Article 1 limits Act 820 of 2003 to urban housing, and Article 18 limits the rent to one percent of the commercial value. These are the figures those two checks rely on.',
                'rental_type' => 'Rental type',
                'rental_type_help' => 'Act 820 applies to urban housing. In commercial premises the rent is set by the market and the CPI is a reference rather than a legal ceiling.',
                'commercial_value' => 'Commercial value',
                'commercial_value_help' => 'Commercial appraisal of the property. Article 18 requires that it not exceed twice the cadastral appraisal.',
                'catastral_appraisal' => 'Cadastral appraisal',
                'catastral_appraisal_help' => 'Current cadastral appraisal. It sets the ceiling for the commercial value under Article 18.',
            ],
            'agreement' => [
                'title' => 'Agreement',
                'description' => 'The agreement document is optional.',
                'hint' => 'Only .pdf, .docx, or images (.jpg, .png), up to 5MB',
            ],
        ],
    ],

    'table' => [
        'columns' => [
            'name' => 'Name',
            'start_date' => 'Start date',
            'end_date' => 'End date',
            'total_months' => 'Months',
            'total_persons' => 'People',
            'monthly_amount' => 'Monthly amount',
            'billing_cadence' => 'Billing cadence',
            'agreement' => 'Agreement',
            'is_active' => 'Active',
            'tenant' => 'Tenant',
            'property' => 'Property',
            'description' => 'Description',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
        ],
    ],

    'infolist' => [
        'sections' => [
            'main' => [
                'name' => 'Name',
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'total_months' => 'Months',
                'total_persons' => 'People',
                'monthly_amount' => 'Monthly amount',
                'agreement' => 'Agreement',
                'is_active' => 'Active',
                'tenant' => 'Tenant',
                'property' => 'Property',
                'description' => 'Description',
                'created_at' => 'Created at',
                'updated_at' => 'Updated at',
            ],
            'agreement' => [
                'title' => 'Agreement',
                'empty' => 'No documents or images have been uploaded',
                'download_image' => 'Download image',
            ],
        ],
    ],
];
