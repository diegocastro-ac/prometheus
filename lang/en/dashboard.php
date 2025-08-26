<?php
return [
    'kpis' => [
        'active_rentals' => 'Active rentals',
        'active_rentals_description' => 'Contracts currently active',
        'monthly_income' => 'Payments collected (month)',
        'monthly_income_desciption' => 'Payments marked as paid',
        'overdue_payments' => 'Active rental payments due',
        'overdue_payments_description' => 'Late rent or utility payment',
    ],
    'charts' => [
        'monthly_income' => [
            'title' => 'Monthly income (last 12 months)',
            'expected' => 'Expected',
            'collected' => 'Collected',
        ],
        'payment_status' => [
            'title' => 'Payment status (current month)',
            'paid' => 'Paid',
            'due' => 'Due',
            'pending' => 'Pending',
        ],
    ],
    'table' => [
        'title' => 'Past or upcoming active rental payments',
        'rental' => 'Rental',
        'tenant' => 'Tenant',
        'property' => 'Property',
        'due_date' => 'Due Date',
        'amount' => 'Amount',
        'status' => 'Status',
    ],
    'status' => [
        'expired'  => 'Expired',
        'expiring' => 'Expiring',
        'paid'     => 'Paid',
    ],
];
