<?php

return [
    'title' => 'Delivery Acts',
    'navigation_label' => 'Delivery Acts',

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
        'inventory' => 'Group items by space. You can create new spaces by typing.',
        'commitments' => 'If there are no commitments, leave the section blank. It will not be printed.',
        'readings' => 'Only fill in the meters that were read during the visit.',
    ],

    'form' => [
        'rental' => 'Rental',
        'type' => 'Act type',
        'occurred_at' => 'Visit date',
        'scheduled_at' => 'Scheduled time',
        'landlord_name' => 'Landlord name',
        'landlord_document' => 'Landlord document',
        'tenant_name' => 'Tenant name',
        'tenant_document' => 'Tenant document',
        'water_reading' => 'Water reading',
        'energy_reading' => 'Energy reading',
        'gas_reading' => 'Gas reading',
        'commitments' => 'Commitments',
        'observations' => 'Observations',
        'space' => 'Space',
        'space_name' => 'Space name',
        'item_name' => 'Item name',
        'item_state' => 'Item state',
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
        'por_reemplazar' => 'Needs replacement',
        'destruido' => 'Destroyed',
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
        'item_note' => 'Painting the patio front door is recommended.',
    ],

    'buttons' => [
        'create' => 'Create act',
        'save' => 'Save',
    ],

    'created' => 'Act created',
    'no_catalog' => 'No space catalog configured. Add it in Settings to inventory items.',
];
