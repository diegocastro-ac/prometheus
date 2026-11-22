<?php

return [
    'title' => 'Finanzas',
    'navigation_label' => 'Finanzas',

    'navigation' => [
        'group' => 'Finanzas',
    ],

    'stats' => [
        'open_balance' => 'Cartera por cobrar',
        'open_balance_hint' => 'Saldo total pendiente',
        'overdue' => 'Vencido',
        'overdue_hint' => ':count factura(s) vencida(s)',
        'income_month' => 'Recaudo del mes',
        'income_no_prev' => 'Sin mes anterior para comparar',
        'income_change' => 'vs. mes anterior: :change',
        'collection_rate' => 'Tasa de recaudo',
        'collection_rate_hint' => 'Últimos 90 días',
        'avg_days_late' => 'Mora promedio',
        'avg_days_late_hint' => 'Días de atraso promedio',
        'expected_month' => 'Facturado del mes',
        'expected_month_hint' => 'Total emitido este mes',
        'active_rentals' => 'Arriendos activos',
        'active_rentals_hint' => 'Inmuebles en arriendo',
    ],

    'period' => [
        'label' => 'Periodo',
        'months' => ':count meses',
    ],

    'sections' => [
        'summary' => 'Resumen general',
        'summary_hint' => 'Las métricas clave de tu portafolio en este momento.',
        'portfolio' => 'Cartera por cobrar',
        'portfolio_hint' => 'Quién te debe, cuánto y desde hace cuánto.',
        'collections' => 'Recaudo y desempeño',
        'collections_hint' => 'Facturado vs. recaudado en los últimos :months meses.',
        'period_total' => 'Recaudado en el periodo',
        'activity' => 'Actividad reciente',
        'activity_hint' => 'Los últimos pagos registrados en tu portafolio.',
    ],

    'aging' => [
        'title' => 'Cartera por edades',
        'total' => 'Total por cobrar',
        'buckets' => [
            'current' => 'Por vencer',
            'd30' => '1–30 días de mora',
            'd60' => '31–60 días de mora',
            'd90' => '61–90 días de mora',
            'd90plus' => 'Más de 90 días',
        ],
    ],

    'debtors' => [
        'title' => 'Mayores deudores',
        'empty' => 'No hay saldos pendientes. ¡Todo al día!',
    ],

    'activity' => [
        'title' => 'Actividad reciente',
        'empty' => 'Aún no hay pagos registrados.',
    ],

    'charts' => [
        'methods' => 'Recaudo por método',
    ],

    'columns' => [
        'date' => 'Fecha',
        'tenant' => 'Inquilino',
        'rental' => 'Arriendo',
        'invoice' => 'Factura',
        'method' => 'Método',
        'amount' => 'Monto',
        'invoices' => 'Facturas',
        'balance' => 'Saldo',
        'oldest_due' => 'Vence desde',
    ],
];
