<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

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
        $userId = Auth::id();
        $labels = [];
        $expected = []; // Scheduled payments
        $collected = []; // Payments marked as paid

        // Last 12 months (includes the current month)
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $labels[] = $date->translatedFormat('M Y');

            $start = $date->copy()->startOfMonth()->toDateString();
            $end   = $date->copy()->endOfMonth()->toDateString();

            $expectedSum = Payment::whereHas('rental', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
                ->whereBetween('date', [$start, $end])
                ->sum('amount');

            $collectedSum = Payment::whereHas('rental', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
                ->whereBetween('date', [$start, $end])
                // No implementa manejo de estado de pago de la propia clase
                ->where('is_rent_paid', true)
                ->sum('amount');

            $expected[] = (float) $expectedSum;
            $collected[] = (float) $collectedSum;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' =>  __('dashboard.charts.monthly_income.expected'),
                    'data' => $expected,
                    'borderDash' => [6, 4],
                ],
                [
                    'label' =>  __('dashboard.charts.monthly_income.collected'),
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
