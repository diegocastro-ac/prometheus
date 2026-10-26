<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/**
 * El grafico muestra la situacion de las facturas del mes, no la de los pagos.
 * Un pago no dice si la factura quedo cubierta; la factura si.
 */
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
        $endOfMonth = $today->copy()->endOfMonth()->toDateString();

        $invoices = Invoice::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->where('is_active', true);
        })
            ->whereBetween('issued_at', [$startOfMonth, $endOfMonth])
            ->get();

        $paidCount = $invoices
            ->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::PAGADA)
            ->count();

        $overdueCount = $invoices
            ->filter(fn (Invoice $invoice) => $invoice->isOverdue())
            ->count();

        $futureCount = $invoices
            ->filter(fn (Invoice $invoice) => in_array(
                $invoice->status,
                [InvoiceStatus::EMITIDA, InvoiceStatus::ANULADA],
                true,
            ))
            ->count();

        return [
            'labels' => [
                __('dashboard.charts.payment_status.paid'),
                __('dashboard.charts.payment_status.due'),
                __('dashboard.charts.payment_status.pending'),
            ],
            'datasets' => [
                [
                    'data' => [
                        $paidCount,
                        $overdueCount,
                        $futureCount,
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
