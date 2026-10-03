<?php

return [
    'title' => 'Delivery acts',
    'navigation_label' => 'Delivery acts',

    'navigation' => [
        'group' => 'Administration',
        'labels' => [
            'singular' => 'delivery act',
            'plural' => 'delivery acts',
        ],
    ],

    'types' => [
        'entrega' => 'Delivery',
        'recepcion' => 'Return',
        'cambio_arrendatario' => 'Tenant change',
    ],

    'sections' => [
        'parties' => 'Parties',
        'readings' => 'Meter readings',
        'inventory' => 'Inventory by space',
        'commitments' => 'Commitments',
        'signatures' => 'Signatures',
    ],

    'descriptions' => [
        'inventory' => 'Group the items by the spaces catalog configured in Settings.',
        'commitments' => 'Leave the section empty if there are no commitments. It will not be printed.',
        'readings' => 'Only fill in the meters that were read during the visit.',
    ],

    'form' => [
        'type' => 'Act type',
        'occurred_at' => 'Visit date',
        'scheduled_at' => 'Scheduled time',
        'landlord_name' => 'Landlord name',
        'landlord_document' => 'Landlord document',
        'tenant_name' => 'Tenant name',
        'tenant_document' => 'Tenant document',
        'water_reading' => 'Water',
        'energy_reading' => 'Energy',
        'gas_reading' => 'Gas',
        'commitments' => 'Commitments',
        'observations' => 'Observations',
        'space' => 'Space',
        'item_name' => 'Item',
        'item_state' => 'Condition',
        'item_note' => 'Note',
        'item_photo' => 'Photo',
        'landlord_signature' => 'Landlord signature',
        'tenant_signature' => 'Tenant signature',
        'signed_at' => 'Signed on',
    ],

    'item_states' => [
        'nuevo' => 'New',
        'bueno' => 'Good',
        'reparable' => 'Repairable',
        'por_reemplazar' => 'To be replaced',
        'destruido' => 'Damaged beyond repair',
    ],

    'table' => [
        'type' => 'Type',
        'tenant' => 'Tenant',
        'occurred_at' => 'Date',
        'items' => 'Items',
        'signed' => 'Signed',
    ],

    'placeholders' => [
        'commitments' => 'The tenant commits to report the water leak before the 15th.',
        'item_note' => 'Painting the main patio door is recommended.',
    ],

    'buttons' => [
        'create' => 'Create act',
        'save' => 'Save',
    ],

    'created' => 'Act created',
    'no_catalog' => 'No spaces catalog configured. Add it in Settings to be able to inventory items.',
];