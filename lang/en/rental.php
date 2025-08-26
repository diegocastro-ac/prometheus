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

    'form' => [
        'sections' => [
            'main' => [
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'name' => 'First name',
                'total_months' => 'Total months',
                'total_persons' => 'Number of people',
                'monthly_amount' => 'Monthly amount',
                'tenant' => 'Tenant',
                'property' => 'Property',
                'is_active' => 'Active',
                'description' => 'Description',
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
