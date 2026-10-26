<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class IncomeChart extends ChartWidget
{
    protected static ?int $sort = 1;

    public function getHeading(): string
    {
        return __('dashboard.charts.monthly_income.title');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $userId = app(CurrentUserContextInterface::class)->id();
        $labels = [];
        $expected = []; // Scheduled payments
        $collected = []; // Payments marked as paid

        // Last 12 months (includes the current month)
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $labels[] = $date->translatedFormat('M Y');

            $start = $date->copy()->startOfMonth()->toDateString();
            $end = $date->copy()->endOfMonth()->toDateString();

            // Esperado: lo que se facturo en el mes.
            $expectedSum = Invoice::whereHas('rental', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where('is_active', true);
            })
                ->whereBetween('issued_at', [$start, $end])
                ->sum('amount');

            // Cobrado: los abonos registrados en el mes. Una fila de pagos es
            // un abono real, asi que ya no hace falta filtrar por is_rent_paid.
            $collectedSum = Payment::whereHas('rental', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where('is_active', true);
            })
                ->whereBetween('date', [$start, $end])
                ->sum('amount');

            $expected[] = (float) $expectedSum;
            $collected[] = (float) $collectedSum;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('dashboard.charts.monthly_income.expected'),
                    'data' => $expected,
                    'borderDash' => [6, 4],
                ],
                [
                    'label' => __('dashboard.charts.monthly_income.collected'),
                    'data' => $collected,
                    'fill' => true,
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
        ];
    }
}
