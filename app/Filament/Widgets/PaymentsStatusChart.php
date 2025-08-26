<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class PaymentsStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Payment status (current month)';

    protected static ?int $sort = 2;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $userId = Auth::id();
        $today = Carbon::today();

        $startOfMonth = $today->copy()->startOfMonth()->toDateString();
        $endOfMonth   = $today->copy()->endOfMonth()->toDateString();

        $baseQuery = Payment::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->where('is_active', true);
        })
            ->whereBetween('date', [$startOfMonth, $endOfMonth]);


        $paidCount = (int) (clone $baseQuery)->where('is_rent_paid', true)->count();

        $overdueCount = (int) (clone $baseQuery)
            ->whereDate('date', '<=', $today)
            ->where('is_rent_paid', false)
            ->count();

        $futureCount = (int) (clone $baseQuery)
            ->whereDate('date', '>', $today)
            ->where('is_rent_paid', false)
            ->count();

        return [
            'labels' => ['Paid', 'Due', 'Pending'],
            'datasets' => [
                [
                    'label' => 'Current month payment status',
                    'data' => [$paidCount, $overdueCount, $futureCount],
                    'backgroundColor' => [
                        'rgba(34,197,94,0.8)',
                        'rgba(239,68,68,0.85)',
                        'rgba(249,115,22,0.85)',
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
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'tooltip' => ['enabled' => true],
            ],
        ];
    }
}
