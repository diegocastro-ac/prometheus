<?php
return [
    'kpis' => [
        'active_rentals'   => 'Alquileres activos',
        'active_rentals_description'   => 'Contratos activos ahora',
        'monthly_income'   => 'Pagos cobrados (mes)',
        'monthly_income_desciption'   => 'Pagos marcados como pagados',
        'overdue_payments' => 'Pagos vencidos de alquileres activos',
        'overdue_payments_description' => 'Pago tardío del alquiler o servicios',
    ],
    'charts' => [
        'monthly_income' => [
            'title' => 'Ingresos mensuales (últimos 12 meses)',
            'expected' => 'Esperado',
            'collected' => 'Recaudado',
        ],
        'payment_status' => [
            'title' => 'Estado de pagos (mes actual)',
            'paid' => 'Pagado',
            'due' => 'Vencido',
            'pending' => 'Pendiente',
        ],
    ],
    'table' => [
        'title'  => 'Pagos vencidos o próximos de alquileres activos',
        'rental' => 'Alquiler',
        'tenant' => 'Inquilino',
        'property' => 'Propiedad',
        'due_date'    => 'Fecha de vencimiento',
        'amount' => 'Monto',
        'status' => 'Estado',
    ],
    'status' => [
        'expired'  => 'Vencido',
        'expiring' => 'Por vencer',
        'paid'     => 'Pagado',
    ],
];
