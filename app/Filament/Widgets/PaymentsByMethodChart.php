<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Services\FinancialAnalysisService;
use Filament\Widgets\ChartWidget;

/**
 * Recaudo de los ultimos 12 meses desglosado por metodo de pago
 * (efectivo, transferencia, consignacion, etc.).
 */
class PaymentsByMethodChart extends ChartWidget
{
    protected static ?int $sort = 4;

    /** Ventana en meses; la ajusta el filtro de periodo de la pagina. */
    public int $months = 12;

    public function getHeading(): string
    {
        return __('finance.charts.methods');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $userId = (int) app(CurrentUserContextInterface::class)->id();
        $rows = app(FinancialAnalysisService::class)->paymentsByMethod($userId, $this->months);

        return [
            'labels' => array_map(fn (array $row): string => $row['label'], $rows),
            'datasets' => [
                [
                    'data' => array_map(fn (array $row): float => $row['amount'], $rows),
                    'backgroundColor' => [
                        'rgba(245,158,11,0.85)',
                        'rgba(59,130,246,0.85)',
                        'rgba(34,197,94,0.85)',
                        'rgba(168,85,247,0.85)',
                        'rgba(239,68,68,0.85)',
                    ],
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'aspectRatio' => 1.6,
            'cutout' => '60%',
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'tooltip' => ['enabled' => true],
            ],
        ];
    }
}
