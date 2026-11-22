<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $userId = app(CurrentUserContextInterface::class)->id();

        // Active rentals
        $activeRentals = Rental::where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        // Income collected this month. A payment row is an abono, so summing it is
        // enough: there is no longer a flag deciding whether it counted.
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth = Carbon::now()->endOfMonth()->toDateString();

        $collectedThisMonth = Payment::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->where('is_active', true);
        })
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // Outstanding invoices. An invoice that still has balance is what the
        // landlord has to act on, so that is what gets counted.
        $overduePaymentsCount = Invoice::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->count();

        return [
            Stat::make(__('dashboard.kpis.active_rentals'), (string) $activeRentals)
                ->description(__('dashboard.kpis.active_rentals_description'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),

            Stat::make(__('dashboard.kpis.monthly_income'), '$'.number_format($collectedThisMonth, 0, ',', '.'))
                ->description(__('dashboard.kpis.monthly_income_desciption'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),

            Stat::make(__('dashboard.kpis.overdue_payments'), (string) $overduePaymentsCount)
                ->description(__('dashboard.kpis.overdue_payments_description'))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->chart([17, 16, 14, 15, 14, 13, 12])
                ->color('danger'),
        ];
    }
}
