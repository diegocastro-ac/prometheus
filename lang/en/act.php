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

    'inventory_tree' => [
        'items' => ':count item|:count items',
        'empty' => 'This act has no registered inventory.',
    ],

    'descriptions' => [
        'inventory' => 'Group items by space. Spaces can have subspaces and are shown with their full path; items are chosen from the landlord\'s master catalogue.',
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
        'space_placeholder' => 'Select or create a space',
        'space_new' => 'Name of the new space',
        'space_new_help' => 'It is added to your catalogue and stays available for future inventories.',
        'space_edit' => 'Edit space',
        'space_name' => 'Space name',
        'space_parent' => 'Parent space',
        'space_parent_none' => 'No parent (root)',
        'item_name' => 'Item name',
        'item_master' => 'Item',
        'item_master_placeholder' => 'Select or create an item',
        'item_master_new' => 'Name of the new item',
        'item_master_new_help' => 'It is added to your catalogue and stays available for future inventories.',
        'item_master_edit' => 'Edit item',
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

    'actions' => [
        'derive' => 'Create from this',
        'inventory_tree' => 'View inventory tree',
    ],

    'buttons' => [
        'create' => 'Create act',
        'save' => 'Save',
        'close' => 'Close',
    ],

    'created' => 'Act created',
    'derived' => 'Act copied into the form',

    // The Builder's messages. The second one changed in substance: telling the
    // user to "add it in Settings" interrupted the inventory to configure a
    // figure they needed right then, and the form now has a button for it.
    'errors' => [
        'no_spaces' => 'Your catalogue has no spaces yet. Use the create button next to the field to add the first one.',
        'space_not_in_catalog' => 'The space ":space" is not in your catalogue. You may use one of the existing ones (:catalog) or create this one with the adjacent button.',
        'space_duplicate' => 'A space with that name already exists at the same level.',
        'invalid_space_parent' => 'The parent space is invalid or would create a cycle in the tree.',
        'inventory_item_required' => 'Each inventory item needs a space and a catalogue item.',
        'master_item_name_required' => 'The inventory item needs a name.',
        'master_item_duplicate' => 'An item with that name already exists in your catalogue.',
        'master_item_not_in_catalog' => 'The item ":item" is not in this landlord\'s catalogue. You may choose one of the existing ones (:catalog) or create this one with the adjacent button.',
        'master_catalog_empty' => 'empty catalogue',
    ],
];
