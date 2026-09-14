<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class PaymentsStatusChart extends ChartWidget
{

    protected static ?int $sort = 2;

    public function getHeading(): string
    {
        return __('dashboard.charts.payment_status.title');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $userId = app(CurrentUserContextInterface::class)->id();
        $today = Carbon::today();

        $startOfMonth = $today->copy()->startOfMonth()->toDateString();
        $endOfMonth   = $today->copy()->endOfMonth()->toDateString();

        $baseQuery = Payment::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->where('is_active', true);
        })
            ->whereBetween('date', [$startOfMonth, $endOfMonth]);

        $payments = $baseQuery->get();

        $paidCount = $payments
            ->filter(fn(Payment $payment) => $payment->status() === PaymentStatus::PAID)
            ->count();

        $overdueCount = $payments
            ->filter(fn(Payment $payment) => $payment->status() === PaymentStatus::OVERDUE)
            ->count();

        $futureCount = $payments
            ->filter(fn(Payment $payment) => in_array(
                $payment->status(),
                [PaymentStatus::PARTIAL, PaymentStatus::PENDING],
                true,
            ))
            ->count();

        return [
            'labels' => [
                __('dashboard.charts.payment_status.paid'),
                __('dashboard.charts.payment_status.due'),
                __('dashboard.charts.payment_status.pending')
            ],
            'datasets' => [
                [
                    'data' => [
                        $paidCount,
                        $overdueCount,
                        $futureCount
                    ],
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
