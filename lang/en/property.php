<?php

return [
    'navigation' => [
        'group' => 'Administration',
        'labels' => [
            'singular' => 'property',
            'plural' => 'Properties',
        ],
    ],

    'form' => [
        'name' => 'Name',
        'address' => 'Address',
        'description' => 'Description',
        'geography_code' => 'City',
        'geography_code_help' => 'The city where the property is located. This is the figure that determines the CPI used for rent adjustments and the reference cadastral value, so it must match the actual location of the property. The DANE publishes the CPI for a group of cities; those not marked "with CPI" can only be adjusted using the national total.',
    ],

    'table' => [
        'columns' => [
            'name' => 'Name',
            'address' => 'Address',
            'description' => 'Description',
            'geography' => 'City',
            'geography_none' => 'No city',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
        ],
    ],

    'infolist' => [
        'name' => 'Name',
        'address' => 'Address',
        'description' => 'Description',
        'geography' => 'City',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
    ],
];
