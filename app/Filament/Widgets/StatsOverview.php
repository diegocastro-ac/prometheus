<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Models\Rental;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $userId = Auth::id();

        // Active rentals
        $activeRentals = Rental::where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        // Income collected this month from rent
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth   = Carbon::now()->endOfMonth()->toDateString();

        $collectedThisMonth = Payment::whereHas('rental', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_rent_paid', true)
            ->sum('amount');

        // Past due payments on rentals
        $today = Carbon::today()->toDateString();

        $overduePaymentsCount = Payment::whereDate('date', '<=', $today)
            ->whereHas('rental', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->where(function ($q) {
                $q->where('is_rent_paid', false)
                    ->orWhere('is_water_paid', false)
                    ->orWhere('is_energy_paid', false)
                    ->orWhere('is_gas_paid', false);
            })
            ->count();

        return [
            Stat::make(__('dashboard.kpis.active_rentals'), (string) $activeRentals)
                ->description(__('dashboard.kpis.active_rentals_description'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),

            Stat::make(__('dashboard.kpis.monthly_income'), '$' . number_format($collectedThisMonth, 0, ',', '.'))
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
