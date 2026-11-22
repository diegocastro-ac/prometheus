<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IncomeChart;
use App\Filament\Widgets\OverduePaymentsTable;
use App\Filament\Widgets\PaymentsStatusChart;
use App\Filament\Widgets\StatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard curado: la pagina por defecto de Filament pinta TODOS los
 * widgets descubiertos, lo que duplicaba "Ingresos mensuales" (IncomeChart
 * y su subclase FinanceIncomeChart) y saturaba la vista con las metricas
 * detalladas que ya viven en Finanzas. Aqui solo va lo esencial.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            StatsOverview::class,
            IncomeChart::class,
            PaymentsStatusChart::class,
            OverduePaymentsTable::class,
        ];
    }
}
