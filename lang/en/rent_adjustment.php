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

    'checks' => [
        'title' => 'Legal checks',
        'blocking' => 'Blocking',
        'warning' => 'Warning',
        'info' => 'For reference',
    ],
    'deviation' => [
        'year' => 'The CPI year is not the one the Act fixes for this effective date: you are applying :chosen where :statutory applies.',
        'city' => 'The CPI applied is the one for :geography, while Article 20 refers to the national total.',
        'pactado' => 'The parties agreed to apply :pct % instead of the full CPI. Article 20 permits this because it says "up to".',
        'excess' => 'The entered rent exceeds the Article 20 ceiling by :amount.',
    ],
    'sections' => [
        'which_and_when' => 'Which rental and from when',
        'with_which_number' => 'With which figure',
        'party_decisions' => 'What the parties decide',
        'party_decisions_help' => 'Only if they agree less than the CPI or depart from Article 20.',
        'party_decisions_why' => 'This combination departs from what Article 20 sets. Confirm it and write down the reason: it is what supports the adjustment if the tenant challenges it.',
        'party_decisions_reasons' => 'Reasons for the departure',
        'legal_checks' => 'Legal checks',
        'legal_checks_help' => 'Result of checking the entered details against Articles 1, 18 and 20 of Act 820 of 2003. Only the checks that have something to report are shown.',
    ],

    'descriptions' => [
        'cap' => 'The cap is calculated on the rent currently in force, not on the original rent: every time the rent changes, Article 20 restarts the twelve months. If the CPI is exceeded, the system warns and asks for the agreement to be written down.',
        'ipc' => 'The CPI year is offered with its percentage next to it, because Article 20 takes the calendar year before the effective one. The document cites the official source of the figure.',
        'anchor' => 'The chain is recalculated from the rental\'s base rent. If you correct one adjustment, the following ones follow automatically.',
    ],

    'table' => [
        'rental' => 'Rental',
        'previous_rent' => 'Previous rent',
        'new_rent' => 'Adjusted rent',
        'ipc' => 'CPI applied',
        'legal_pct' => 'Legal CPI',
        'legal_cap' => 'Cap per CPI',
        'geography' => 'CPI series',
        'effective_from' => 'Effective',
        'notified_on' => 'Notified',
        'status' => 'Status',
        'sequence' => 'Chain position',
    ],

    'statuses' => [
        'within_cap' => 'Within CPI',
        'exceeds_cap' => 'Exceeds CPI',
    ],

    'states' => [
        'recorded' => 'Recorded',
        'notified' => 'Notified',
        'applied' => 'Applied',
    ],

    'notification_methods' => [
        'postal' => 'Authorised postal service',
        'contractual' => 'Mechanism agreed in the lease',
        'in_person' => 'Hand delivery with receipt',
        'email' => 'Email',
        'label' => 'Notification method',
        'help' => 'Article 20, paragraph 2, names the authorised postal service and the mechanism agreed in the lease. Without the method, the date alone does not evidence the communication and the increase is unenforceable.',
    ],

    'form' => [
        'rental_id' => 'Rental',
        'new_rent' => 'Adjusted rent',
        'new_rent_help' => 'The rent in force is $:previous. With the :year CPI (:ipc%), Article 20 supports $:cap.',
        'new_rent_help_above' => 'It exceeds the Article 20 ceiling by the difference shown in the table.',
        'new_rent_help_below' => 'It falls short of the Article 20 ceiling, which the Act permits.',
        'new_rent_help_at_cap' => 'It matches the Article 20 ceiling (:cap).',
        'effective_from' => 'Effective from',
        'effective_from_help' => 'Article 20 counts the twelve months from the last rent change, not from the start of the lease.',
        'effective_from_overdue' => 'This rental has already met its twelve months. The period ran until :date.',
        'effective_from_pick_rental' => 'Select a rental and the date will be proposed: the anniversary of its last adjustment.',
        'business_day_note' => 'That anniversary falls on a non-business day, so :suggested is proposed so the notice can be delivered by the authorised postal service. The legal deadline is still :legal.',
        'ipc_year' => 'CPI year',
        'ipc_basis' => 'CPI basis',
        'ipc_basis_help' => 'Article 20 refers to the consumer price index of the national total. Applying the index of a single city is possible, but it departs from its wording and requires the reason to be recorded.',
        'geography_code' => 'City',
        'geography_code_no_year' => 'There is no CPI data for :geography for :year.',
        'pactado_pct' => 'Agreed percentage',
        'pactado_pct_help' => 'Article 20 says "up to", so the parties may agree less than the CPI. Leave it blank to apply the full CPI.',
        'ipc_outside_statutory_year' => 'I confirm this departs from Article 20',
        'ipc_outside_statutory_year_help' => 'This box appears when the CPI year, the city or the amount depart from what Article 20 sets. Ticking it does not prevent the record: it requires the reason to be stated in the notes, which is what supports the adjustment should it be challenged.',
        'notes' => 'Notes',
        'notes_help' => 'State here the written agreement, the reason for departing from Article 20, or any other circumstance the tenant should know about.',
        'notes_help_required' => 'Required here: it is what supports the adjustment if the tenant challenges it.',
        'notification_method' => 'Notification method',
        'notification_date' => 'Notification date',
        'select' => 'Select adjustment',
    ],

    'preview' => [
        'title' => 'Adjustment calculation',
        'current_rent' => 'Rent in force',
        'current_rent_hint' => 'The figure the CPI is applied to. It is the rent from the last adjustment applied, not the one in the original lease.',
        'applied_ipc' => 'CPI applied',
        'applied_ipc_value' => ':year variation for :geography',
        'increase' => 'Applied increase',
        'cap' => 'Article 20 ceiling',
        'cap_hint' => 'Rent in force plus the increase. This is what Article 20 supports.',
        'selected_rent' => 'Rent to be recorded',
        'selected_rent_hint' => 'The value entered in the field above.',
        'above_cap' => 'Excess over the ceiling',
        'above_cap_hint' => 'Article 20 phrases this as a ceiling. It may be recorded where the parties agree and document it.',
        'below_cap' => 'Difference below the ceiling',
        'national_geography' => 'national total',
    ],
    'placeholders' => [
        'notes' => 'Written agreement between the parties signed on January 15, 2026, to apply half the CPI.',
    ],

    'buttons' => [
        'create' => 'Record adjustment',
        'letter' => 'Adjustment letter',
        'apply' => 'Apply to rental',
        'mark_notified' => 'Mark as notified',
        'recalculate' => 'Recalculate chain',
        'actions' => 'Actions',
    ],

    'notifications' => [
        'created' => 'Adjustment recorded',
        'applied' => 'Rent updated',
        'needs_agreement' => 'The increase exceeds what the CPI gives. The reason is recorded in the notes.',
        'recalculated' => 'Chain recalculated',
        'missing_ipc' => 'The December CPI of the preceding year is missing. The official figure has not yet been loaded into the system.',
    ],
];
