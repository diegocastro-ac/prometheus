<?php

return [
    'title' => 'Rent adjustments',
    'navigation_label' => 'Adjustments',

    'navigation' => [
        'group' => 'Administration',
        'labels' => [
            'singular' => 'adjustment',
            'plural' => 'adjustments',
        ],
    ],

    'sections' => [
        'general' => 'Adjustment details',
        'legal' => 'Legal cap',
    ],

    'descriptions' => [
        'legal' => 'Article 20 of Law 820 of 2003 caps the increase at the CPI of the immediately preceding calendar year and requires notifying the amount and the effective date.',
        'cap' => 'The cap is calculated from the current rent and the previous year CPI. An increase above the cap only takes effect with a written agreement.',
        'ipc' => 'The CPI is recorded once per year with its official source. The document cites that source.',
    ],

    'table' => [
        'rental' => 'Rental',
        'previous_rent' => 'Previous rent',
        'new_rent' => 'Adjusted rent',
        'increase' => 'Increase',
        'ipc' => 'CPI applied',
        'legal_cap' => 'Legal cap',
        'effective_from' => 'Effective',
        'notified_on' => 'Notified',
        'status' => 'Status',
    ],

    'statuses' => [
        'within_cap' => 'Within cap',
        'exceeds_cap' => 'Exceeds cap',
        'notified' => 'Notified',
        'pending_notification' => 'Not notified',
    ],

    'form' => [
        'rental_id' => 'Rental',
        'new_rent' => 'Adjusted rent',
        'effective_from' => 'Effective from',
        'ipc_year' => 'CPI year',
        'ipc_outside_statutory_year' => 'Depart from the year the law sets',
        'ipc_outside_statutory_year_help' => 'Article 20 requires the CPI of the calendar year before the effective date. If the chosen year is not that one, tick this and explain the reason in the notes; without both the record is rejected.',
        'notes' => 'Notes',
        'notes_help' => 'If the increase exceeds the cap, record the written agreement here. Without that note the adjustment is not applied to the rental.',
    ],

    'placeholders' => [
        'notes' => 'Written agreement between the parties signed on January 15, 2026.',
    ],

    'buttons' => [
        'create' => 'Record adjustment',
        'letter' => 'Adjustment letter',
        'apply' => 'Apply to rental',
        'mark_notified' => 'Mark as notified',
    ],

    'notifications' => [
        'created' => 'Adjustment recorded',
        'applied' => 'Rent updated',
        'notified' => 'Adjustment marked as notified',
        'needs_agreement' => 'The increase exceeds the CPI cap. Record the written agreement in the notes to apply it.',
    ],
];
