<?php

return [
    'title' => 'Finances',
    'navigation_label' => 'Finances',

    'navigation' => [
        'group' => 'Administration',
    ],

    'stats' => [
        'open_balance' => 'Receivables',
        'open_balance_hint' => 'Total outstanding balance',
        'overdue' => 'Overdue',
        'overdue_hint' => ':count overdue invoice(s)',
        'income_month' => 'This month collections',
        'income_no_prev' => 'No previous month to compare',
        'income_change' => 'vs. last month: :change',
        'collection_rate' => 'Collection rate',
        'collection_rate_hint' => 'Last 90 days',
        'avg_days_late' => 'Avg. days late',
        'avg_days_late_hint' => 'Average payment delay',
        'expected_month' => 'Billed this month',
        'expected_month_hint' => 'Total invoiced this month',
        'active_rentals' => 'Active rentals',
        'active_rentals_hint' => 'Occupied properties',
    ],

    'period' => [
        'label' => 'Period',
        'months' => ':count months',
    ],

    'sections' => [
        'summary' => 'General summary',
        'summary_hint' => 'The key metrics of your portfolio right now.',
        'portfolio' => 'Receivables',
        'portfolio_hint' => 'Who owes you, how much and for how long.',
        'collections' => 'Collections & performance',
        'collections_hint' => 'Billed vs. collected over the last :months months.',
        'period_total' => 'Collected in period',
        'activity' => 'Recent activity',
        'activity_hint' => 'The latest payments recorded across your portfolio.',
    ],

    'aging' => [
        'title' => 'Receivables aging',
        'total' => 'Total receivable',
        'buckets' => [
            'current' => 'Not yet due',
            'd30' => '1–30 days late',
            'd60' => '31–60 days late',
            'd90' => '61–90 days late',
            'd90plus' => 'Over 90 days',
        ],
    ],

    'debtors' => [
        'title' => 'Top debtors',
        'empty' => 'No outstanding balances. All up to date!',
    ],

    'activity' => [
        'title' => 'Recent activity',
        'empty' => 'No payments recorded yet.',
    ],

    'charts' => [
        'methods' => 'Collections by method',
    ],

    'columns' => [
        'date' => 'Date',
        'tenant' => 'Tenant',
        'rental' => 'Rental',
        'invoice' => 'Invoice',
        'method' => 'Method',
        'amount' => 'Amount',
        'invoices' => 'Invoices',
        'balance' => 'Balance',
        'oldest_due' => 'Due since',
    ],
];
